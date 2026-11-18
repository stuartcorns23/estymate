<?php

namespace App\Enums;

enum DocumentType: string
{
    case Estimate = 'estimate';
    case Invoice = 'invoice';
    // A preliminary bill sent before the work or delivery. Not a VAT invoice, so it never counts as income.
    case ProForma = 'proforma';

    public function label(): string
    {
        return match ($this) {
            self::Estimate => 'Estimate',
            self::Invoice => 'Invoice',
            self::ProForma => 'Pro-forma invoice',
        };
    }

    /**
     * The URL segment and route name prefix, e.g. "invoices".
     */
    public function plural(): string
    {
        return $this->value.'s';
    }

    public function initialStatus(): DocumentStatus
    {
        return DocumentStatus::Draft;
    }

    /**
     * Statuses a person can move this type of document to by hand.
     *
     * @return list<DocumentStatus>
     */
    public function manualStatuses(): array
    {
        return match ($this) {
            self::Estimate => [DocumentStatus::Draft, DocumentStatus::Sent, DocumentStatus::Accepted, DocumentStatus::Declined],
            // Paid is set by recording payments, so it isn't chosen by hand.
            self::Invoice => [DocumentStatus::Draft, DocumentStatus::Sent, DocumentStatus::Void],
            // Invoiced is set by converting it into a real invoice.
            self::ProForma => [DocumentStatus::Draft, DocumentStatus::Sent, DocumentStatus::Void],
        };
    }

    /**
     * Whether this type can be turned into an invoice.
     */
    public function convertsToInvoice(): bool
    {
        return $this !== self::Invoice;
    }

    /**
     * Whether the document asks for payment by a due date, rather than being valid until a date.
     */
    public function requestsPayment(): bool
    {
        return $this !== self::Estimate;
    }
}
