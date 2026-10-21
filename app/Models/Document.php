<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use BelongsToBusiness, HasFactory, SoftDeletes;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'public_token',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'issue_date',
        'due_date',
        'reference',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'last_reminder_at' => 'datetime',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Document $document) {
            $document->public_token ??= Str::random(40);
        });
    }

    /**
     * The page clients use to view, download and pay this document without an account.
     */
    public function publicUrl(): string
    {
        if (! $this->public_token) {
            $this->forceFill(['public_token' => Str::random(40)])->save();
        }

        return route('public.documents.show', $this->public_token);
    }

    /**
     * Whether the client can pay this invoice online right now.
     */
    public function canBePaidOnline(): bool
    {
        return $this->type === DocumentType::Invoice
            && $this->status === DocumentStatus::Sent
            && $this->balanceDue() > 0
            && $this->business->stripe_charges_enabled;
    }

    /**
     * @param  Builder<Document>  $query
     */
    public function scopeOfType(Builder $query, DocumentType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return HasMany<DocumentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on')->orderBy('id');
    }

    /**
     * The schedule that created this invoice, if any.
     *
     * @return BelongsTo<RecurringInvoice, $this>
     */
    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /**
     * The schedule that repeats this invoice, if any.
     *
     * @return HasOne<RecurringInvoice, $this>
     */
    public function repeatSchedule(): HasOne
    {
        return $this->hasOne(RecurringInvoice::class, 'template_document_id');
    }

    public function balanceDue(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }

    /**
     * Recalculate the amount paid and move the invoice in or out of "paid".
     */
    public function refreshPayments(): void
    {
        $this->amount_paid = (int) $this->payments()->sum('amount');

        if ($this->total > 0 && $this->amount_paid >= $this->total) {
            $this->status = DocumentStatus::Paid;
            $this->paid_at ??= $this->payments()->latest('paid_on')->value('paid_on') ?? now();
        } elseif ($this->status === DocumentStatus::Paid) {
            $this->status = DocumentStatus::Sent;
            $this->paid_at = null;
        }

        $this->save();
    }

    /**
     * The estimate an invoice was created from.
     *
     * @return BelongsTo<Document, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    /**
     * The invoice an estimate was converted into.
     *
     * @return HasOne<Document, $this>
     */
    public function convertedTo(): HasOne
    {
        return $this->hasOne(Document::class, 'source_document_id');
    }

    /**
     * Paid, void and converted documents are final and can't be edited.
     */
    public function isEditable(): bool
    {
        return $this->amount_paid === 0
            && ! in_array($this->status, [DocumentStatus::Paid, DocumentStatus::Void, DocumentStatus::Invoiced], true);
    }

    /**
     * Only drafts can be deleted; issued invoices should be voided so the numbering has no gaps.
     */
    public function isDeletable(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isOverdue(): bool
    {
        return $this->type === DocumentType::Invoice
            && $this->status === DocumentStatus::Sent
            && $this->due_date?->isBefore(today());
    }

    /**
     * Recalculate the document's totals from its line items.
     */
    public function refreshTotals(): void
    {
        $items = $this->items()->get();

        $this->subtotal = (int) $items->sum('net_amount');
        $this->tax_total = (int) $items->sum('tax_amount');
        $this->total = $this->subtotal + $this->tax_total;
    }

    /**
     * Totals grouped by VAT rate, as UK VAT invoices must show.
     *
     * @return list<array{rate: string, net: int, tax: int}>
     */
    public function vatBreakdown(): array
    {
        return $this->items
            ->groupBy(fn (DocumentItem $item) => $item->tax_rate)
            ->map(fn ($items, $rate) => [
                'rate' => (string) $rate,
                'net' => (int) $items->sum('net_amount'),
                'tax' => (int) $items->sum('tax_amount'),
            ])
            ->sortByDesc('rate')
            ->values()
            ->all();
    }
}
