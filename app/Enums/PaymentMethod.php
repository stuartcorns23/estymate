<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Stripe = 'stripe';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::Card => 'Card',
            self::Cash => 'Cash',
            self::Cheque => 'Cheque',
            self::Stripe => 'Online (Stripe)',
            self::Other => 'Other',
        };
    }

    /**
     * Methods a person can choose when recording a payment by hand.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::BankTransfer, self::Card, self::Cash, self::Cheque, self::Other];
    }
}
