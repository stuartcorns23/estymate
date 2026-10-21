<?php

namespace App\Enums;

enum DocumentType: string
{
    case Estimate = 'estimate';
    case Invoice = 'invoice';

    public function label(): string
    {
        return match ($this) {
            self::Estimate => 'Estimate',
            self::Invoice => 'Invoice',
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
        };
    }
}
