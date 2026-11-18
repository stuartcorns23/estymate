<?php

namespace App\Enums;

enum DocumentType: string
{
    case Estimate = 'estimate';
    case Invoice = 'invoice';
    // A preliminary bill sent before the work or delivery. Not a VAT invoice, so it never counts as income.
    case ProForma = 'proforma';
    // Cancels all or part of an invoice. It reduces income and VAT, and comes off the invoice's balance.
    case CreditNote = 'creditnote';
    // Lists what was delivered, with no prices. It never affects money.
    case DeliveryNote = 'deliverynote';

    public function label(): string
    {
        return match ($this) {
            self::Estimate => 'Estimate',
            self::Invoice => 'Invoice',
            self::ProForma => 'Pro-forma invoice',
            self::CreditNote => 'Credit note',
            self::DeliveryNote => 'Delivery note',
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
            // Sent means issued: only then does it count against the invoice and the tax figures.
            self::CreditNote => [DocumentStatus::Draft, DocumentStatus::Sent, DocumentStatus::Void],
            self::DeliveryNote => [DocumentStatus::Draft, DocumentStatus::Sent, DocumentStatus::Delivered],
        };
    }

    /**
     * Whether this type can be turned into an invoice.
     */
    public function convertsToInvoice(): bool
    {
        return in_array($this, [self::Estimate, self::ProForma], true);
    }

    /**
     * Whether the document asks for payment by a due date, rather than being valid until a date.
     */
    public function requestsPayment(): bool
    {
        return in_array($this, [self::Invoice, self::ProForma], true);
    }

    /**
     * Whether the document has a second date: a due date, or for estimates a valid-until date.
     */
    public function hasDueDate(): bool
    {
        return in_array($this, [self::Estimate, self::Invoice, self::ProForma], true);
    }

    /**
     * Whether prices and totals appear on the document. Delivery notes only list what was delivered.
     */
    public function showsPrices(): bool
    {
        return $this !== self::DeliveryNote;
    }

    /**
     * Whether it can only be made from an invoice, rather than started from scratch.
     */
    public function createdFromInvoice(): bool
    {
        return $this === self::CreditNote;
    }

    /**
     * Whether a delivery note can be made from it.
     */
    public function makesDeliveryNotes(): bool
    {
        return in_array($this, [self::Estimate, self::Invoice, self::ProForma], true);
    }

    /**
     * The plan limit that creating one counts towards, if any. Credit notes correct invoices, so they're never limited.
     */
    public function planLimit(): ?string
    {
        return $this === self::CreditNote ? null : "{$this->plural()}_per_month";
    }
}
