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

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use BelongsToBusiness, HasFactory, SoftDeletes;

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
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
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
        return ! in_array($this->status, [DocumentStatus::Paid, DocumentStatus::Void, DocumentStatus::Invoiced], true);
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
