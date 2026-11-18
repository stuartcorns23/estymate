<?php

namespace App\Actions;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueCreditNote
{
    /**
     * Move a credit note to a new status and update the invoice it credits. Issuing it ("sent")
     * takes it off the invoice's balance; voiding it or moving it back to draft puts it back.
     */
    public function handle(Document $creditNote, DocumentStatus $status): void
    {
        DB::transaction(function () use ($creditNote, $status) {
            $invoice = $creditNote->source()->lockForUpdate()->firstOrFail();

            if ($status === DocumentStatus::Sent && $creditNote->status !== DocumentStatus::Sent) {
                if (! in_array($invoice->status, [DocumentStatus::Sent, DocumentStatus::Paid, DocumentStatus::Credited], true)) {
                    throw ValidationException::withMessages([
                        'status' => "Invoice {$invoice->number} is {$invoice->status->value}, so it can't be credited.",
                    ]);
                }

                self::ensureFits($creditNote, $creditNote->total, $invoice);
            }

            $creditNote->status = $status;
            $creditNote->save();

            $invoice->refreshCredits();
        });
    }

    /**
     * Stop a credit note crediting more than is left of the invoice once other issued credit notes are counted.
     */
    public static function ensureFits(Document $creditNote, int $total, ?Document $invoice = null, string $key = 'status'): void
    {
        $invoice ??= $creditNote->source;

        $left = $invoice->total - (int) $invoice->creditNotes()
            ->where('status', DocumentStatus::Sent)
            ->whereKeyNot($creditNote->id)
            ->sum('total');

        if ($total > $left) {
            throw ValidationException::withMessages([
                $key => 'That is more than the '.Money::format(max(0, $left), $invoice->currency)." left to credit on invoice {$invoice->number}.",
            ]);
        }
    }
}
