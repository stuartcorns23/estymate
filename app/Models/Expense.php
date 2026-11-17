<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A purchase invoice, bill or receipt the business has to pay.
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use BelongsToBusiness, HasFactory, SoftDeletes;

    /**
     * Uploaded invoices and receipts are kept on this private disk.
     */
    public const DISK = 'local';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'supplier',
        'reference',
        'date',
        'category',
        'description',
        'total',
        'vat_amount',
        'paid_on',
        'notes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'attachment_path',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'net_amount',
        'has_attachment',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'paid_on' => 'date:Y-m-d',
            'category' => ExpenseCategory::class,
            'total' => 'integer',
            'vat_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Expense $expense) {
            $expense->currency ??= Business::withoutGlobalScopes()->whereKey($expense->business_id)->value('currency') ?? 'GBP';
        });

        static::forceDeleted(fn (Expense $expense) => $expense->deleteAttachment());
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function hasAttachment(): Attribute
    {
        return Attribute::get(fn () => $this->attachment_path !== null);
    }

    /**
     * @return Attribute<int, never>
     */
    protected function netAmount(): Attribute
    {
        return Attribute::get(fn () => $this->total - $this->vat_amount);
    }

    public function deleteAttachment(): void
    {
        if ($this->attachment_path) {
            Storage::disk(self::DISK)->delete($this->attachment_path);
        }

        $this->attachment_path = null;
        $this->attachment_name = null;
    }
}
