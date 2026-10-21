<?php

namespace App\Models;

use App\Enums\BusinessRole;
use App\Enums\DocumentType;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

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
    ];

    protected static function booted(): void
    {
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
}
