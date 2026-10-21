<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentItem extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'position',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'tax_rate',
        'net_amount',
        'tax_amount',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'integer',
            'tax_rate' => 'decimal:2',
            'net_amount' => 'integer',
            'tax_amount' => 'integer',
        ];
    }

    /**
     * Work out the net and VAT amounts in pence for a line.
     *
     * @return array{net_amount: int, tax_amount: int}
     */
    public static function amountsFor(string|float $quantity, int $unitPrice, string|float $taxRate): array
    {
        $net = (int) round((float) $quantity * $unitPrice);

        return [
            'net_amount' => $net,
            'tax_amount' => (int) round($net * (float) $taxRate / 100),
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
