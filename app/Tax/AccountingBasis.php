<?php

namespace App\Tax;

enum AccountingBasis: string
{
    /** Income when the money arrives, expenses when they're paid. HMRC's default for sole traders. */
    case Cash = 'cash';

    /** Income when it's invoiced, expenses when they're billed. */
    case Accruals = 'accruals';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash basis',
            self::Accruals => 'Traditional (accruals) basis',
        };
    }
}
