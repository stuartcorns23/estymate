<?php

namespace App\Models;

use App\Billing\Plan;
use App\Billing\PlanLimitReached;
use App\Enums\BusinessRole;
use App\Enums\DocumentType;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use Billable, HasFactory;

    /**
     * UK VAT rates every new business starts with.
     *
     * @var list<array{name: string, rate: string, is_default: bool}>
     */
    public const DEFAULT_TAX_RATES = [
        ['name' => 'Standard rate', 'rate' => '20.00', 'is_default' => true],
        ['name' => 'Reduced rate', 'rate' => '5.00', 'is_default' => false],
        ['name' => 'Zero rate', 'rate' => '0.00', 'is_default' => false],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'address_line_1',
        'address_line_2',
        'city',
        'county',
        'postcode',
        'country',
        'vat_number',
        'company_number',
        'currency',
        'estimate_prefix',
        'invoice_prefix',
        'payment_terms_days',
        'payment_details',
        'send_reminders',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'send_reminders' => 'boolean',
            'stripe_charges_enabled' => 'boolean',
            'trial_ends_at' => 'datetime',
            'payment_terms_days' => 'integer',
        ];
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'plan_override',
    ];

    protected static function booted(): void
    {
        static::creating(function (Business $business) {
            $business->trial_ends_at ??= now()->addDays(config('plans.trial_days'));
        });

        static::created(function (Business $business) {
            $business->taxRates()->createMany(self::DEFAULT_TAX_RATES);
        });
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<BusinessInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(BusinessInvitation::class);
    }

    /**
     * Tax rates are read through the relation here, so the current-business scope is bypassed.
     *
     * @return HasMany<TaxRate, $this>
     */
    public function taxRates(): HasMany
    {
        return $this->hasMany(TaxRate::class)->withoutGlobalScopes();
    }

    /**
     * Add a user to the business with the given role.
     */
    public function addUser(User $user, BusinessRole $role): void
    {
        $this->users()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);

        if ($user->current_business_id === null) {
            $user->forceFill(['current_business_id' => $this->id])->save();
        }
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->withoutGlobalScopes();
    }

    /**
     * Claim the next document number for the type, e.g. "INV-0007".
     *
     * Call inside a transaction so two people saving at once can't get the same number.
     */
    public function claimDocumentNumber(DocumentType $type): string
    {
        $column = "next_{$type->value}_number";
        $prefix = $type === DocumentType::Estimate ? $this->estimate_prefix : $this->invoice_prefix;

        $next = (int) static::whereKey($this->id)->lockForUpdate()->value($column);
        static::whereKey($this->id)->update([$column => $next + 1]);
        $this->{$column} = $next + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * How much the business has used, for subscription plan limits.
     *
     * Deleted clients and documents still count towards the month they were created in.
     *
     * @return array{clients: int, estimates_this_month: int, invoices_this_month: int}
     */
    public function usage(): array
    {
        $documentsThisMonth = $this->documents()
            ->withTrashed()
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        return [
            'clients' => $this->clients()->count(),
            'estimates_this_month' => (int) ($documentsThisMonth[DocumentType::Estimate->value] ?? 0),
            'invoices_this_month' => (int) ($documentsThisMonth[DocumentType::Invoice->value] ?? 0),
        ];
    }

    /**
     * The plan the business is on: a comped plan, its subscription, its free trial, or the default.
     */
    public function plan(): Plan
    {
        if ($this->plan_override) {
            return Plan::find($this->plan_override);
        }

        $subscription = $this->subscription();

        if ($subscription?->valid()) {
            return Plan::forStripePrice($subscription->stripe_price) ?? Plan::find(config('plans.default'));
        }

        if ($this->onGenericTrial()) {
            return Plan::find(config('plans.trial_plan'));
        }

        return Plan::find(config('plans.default'));
    }

    /**
     * Whether the business is using the free trial rather than a paid or comped plan.
     */
    public function isOnFreeTrial(): bool
    {
        return ! $this->plan_override && ! $this->subscribed() && $this->onGenericTrial();
    }

    /**
     * How much of each limited resource the business is using now.
     *
     * @return array<string, int>
     */
    public function planUsage(): array
    {
        $usage = $this->usage();

        return [
            'clients' => $usage['clients'],
            'invoices_per_month' => $usage['invoices_this_month'],
            'estimates_per_month' => $usage['estimates_this_month'],
            'team_members' => $this->users()->count() + $this->invitations()->count(),
        ];
    }

    /**
     * Stop here if adding one more of the resource would go over the plan's limit.
     *
     * @throws PlanLimitReached
     */
    public function ensureWithinLimit(string $resource): void
    {
        $plan = $this->plan();
        $limit = $plan->limit($resource);

        if ($limit !== null && $this->planUsage()[$resource] >= $limit) {
            throw new PlanLimitReached($plan, $resource);
        }
    }
}
