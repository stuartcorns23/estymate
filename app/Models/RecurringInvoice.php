<?php

namespace App\Models;

use App\Enums\RecurringFrequency;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringInvoice extends Model
{
    use BelongsToBusiness;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'frequency',
        'next_issue_date',
        'ends_on',
        'auto_send',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => RecurringFrequency::class,
            'next_issue_date' => 'date',
            'ends_on' => 'date',
            'auto_send' => 'boolean',
            'is_active' => 'boolean',
            'last_issued_at' => 'datetime',
        ];
    }

    /**
     * Schedules with an invoice due today or earlier.
     *
     * @param  Builder<RecurringInvoice>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereDate('next_issue_date', '<=', today())
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereColumn('next_issue_date', '<=', 'ends_on'));
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'template_document_id')->withoutGlobalScopes();
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
