<?php

namespace App\Tax;

use App\Enums\ExpenseCategory;

/**
 * The allowable expense boxes on HMRC's self-employment pages (SA103), with the
 * box numbers on the full (SA103F) and short (SA103S) forms for 2025-26.
 */
enum Sa103Box: string
{
    case Goods = 'goods';
    case Subcontractors = 'subcontractors';
    case Staff = 'staff';
    case Travel = 'travel';
    case Premises = 'premises';
    case Office = 'office';
    case Advertising = 'advertising';
    case BankCharges = 'bank_charges';
    case Professional = 'professional';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Goods => 'Cost of goods bought for resale or goods used',
            self::Subcontractors => 'Construction industry – payments to subcontractors',
            self::Staff => 'Wages, salaries and other staff costs',
            self::Travel => 'Car, van and travel expenses',
            self::Premises => 'Rent, rates, power and insurance costs',
            self::Office => 'Phone, fax, stationery and other office costs',
            self::Advertising => 'Advertising and business entertainment costs',
            self::BankCharges => 'Bank, credit card and other financial charges',
            self::Professional => 'Accountancy, legal and other professional fees',
            self::Other => 'Other business expenses',
        };
    }

    public function fullFormBox(): int
    {
        return match ($this) {
            self::Goods => 17,
            self::Subcontractors => 18,
            self::Staff => 19,
            self::Travel => 20,
            self::Premises => 21,
            self::Office => 23,
            self::Advertising => 24,
            self::BankCharges => 26,
            self::Professional => 28,
            self::Other => 30,
        };
    }

    /**
     * The short form has fewer boxes, so some of these share one.
     */
    public function shortFormBox(): int
    {
        return match ($this) {
            self::Goods => 11,
            self::Travel => 12,
            self::Staff => 13,
            self::Premises => 14,
            self::Professional => 16,
            self::BankCharges => 17,
            self::Office => 18,
            self::Subcontractors, self::Advertising, self::Other => 19,
        };
    }

    public static function forCategory(ExpenseCategory $category): self
    {
        return match ($category) {
            ExpenseCategory::Materials => self::Goods,
            ExpenseCategory::Subcontractors => self::Subcontractors,
            ExpenseCategory::Staff => self::Staff,
            ExpenseCategory::Travel, ExpenseCategory::Vehicle => self::Travel,
            ExpenseCategory::Premises, ExpenseCategory::Insurance => self::Premises,
            ExpenseCategory::Office, ExpenseCategory::Software => self::Office,
            ExpenseCategory::Marketing => self::Advertising,
            ExpenseCategory::BankCharges => self::BankCharges,
            ExpenseCategory::Professional => self::Professional,
            ExpenseCategory::Equipment, ExpenseCategory::Training, ExpenseCategory::Other => self::Other,
        };
    }
}
