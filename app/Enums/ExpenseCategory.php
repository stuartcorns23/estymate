<?php

namespace App\Enums;

/**
 * Expense categories, based on HMRC's groups of allowable business expenses.
 */
enum ExpenseCategory: string
{
    case Materials = 'materials';
    case Travel = 'travel';
    case Vehicle = 'vehicle';
    case Premises = 'premises';
    case Equipment = 'equipment';
    case Software = 'software';
    case Office = 'office';
    case Marketing = 'marketing';
    case Professional = 'professional';
    case Subcontractors = 'subcontractors';
    case Staff = 'staff';
    case Insurance = 'insurance';
    case BankCharges = 'bank_charges';
    case Training = 'training';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Materials => 'Stock & materials',
            self::Travel => 'Travel',
            self::Vehicle => 'Vehicle & fuel',
            self::Premises => 'Rent & utilities',
            self::Equipment => 'Equipment',
            self::Software => 'Software & subscriptions',
            self::Office => 'Office, phone & stationery',
            self::Marketing => 'Advertising & marketing',
            self::Professional => 'Accountancy & legal',
            self::Subcontractors => 'Subcontractors',
            self::Staff => 'Staff costs',
            self::Insurance => 'Insurance',
            self::BankCharges => 'Bank & finance charges',
            self::Training => 'Training',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category) => ['value' => $category->value, 'label' => $category->label()], self::cases());
    }
}
