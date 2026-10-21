<?php

namespace App\Actions;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Controllers\DocumentActionController;
use App\Mail\DocumentMail;
use App\Models\Document;
use App\Models\RecurringInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class IssueRecurringInvoice
{
    /**
     * Create the next invoice for a schedule, send it if asked, and move the schedule on.
     */
    public function handle(RecurringInvoice $schedule): Document
    {
        $invoice = DB::transaction(function () use ($schedule) {
            $template = $schedule->template()->with('items')->firstOrFail();
            $business = $template->business;
            $issueDate = $schedule->next_issue_date->copy();

            $invoice = $template->replicate([
                'number', 'status', 'sent_at', 'paid_at', 'amount_paid', 'reminders_sent', 'last_reminder_at', 'source_document_id', 'public_token',
            ]);
            $invoice->type = DocumentType::Invoice;
            $invoice->status = DocumentStatus::Draft;
            $invoice->number = $business->claimDocumentNumber(DocumentType::Invoice);
            $invoice->issue_date = $issueDate;
            $invoice->due_date = $issueDate->copy()->addDays($business->payment_terms_days);
            $invoice->recurring_invoice_id = $schedule->id;
            $invoice->save();

            foreach ($template->items as $item) {
                $invoice->items()->create($item->replicate(['document_id'])->getAttributes());
            }

            $schedule->next_issue_date = $schedule->frequency->next($issueDate);
            $schedule->last_issued_at = now();
            $schedule->save();

            return $invoice;
        });

        if ($schedule->auto_send && $invoice->client->email) {
            Mail::to($invoice->client->email)->send(new DocumentMail($invoice, DocumentActionController::defaultMessage($invoice)));

            $invoice->status = DocumentStatus::Sent;
            $invoice->sent_at = now();
            $invoice->save();
        }

        return $invoice;
    }
}
