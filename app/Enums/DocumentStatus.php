<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
    case Void = 'void';
    // A credit note covers the whole invoice, with nothing paid on it.
    case Credited = 'credited';
    case Delivered = 'delivered';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
