<?php

namespace App\Http\Controllers;

use App\Actions\ConvertToInvoice;
use App\Actions\CopyDocument;
use App\Actions\IssueCreditNote;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Mail\DocumentMail;
use App\Models\Document;
use App\Support\DocumentPdf;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DocumentActionController extends Controller
{
    /**
     * Download the document as a PDF, or show it in the browser with ?inline=1.
     */
    public function pdf(Request $request, Document $document): Response
    {
        $this->ensureType($request, $document);

        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response(DocumentPdf::render($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.DocumentPdf::filename($document).'"',
        ]);
    }

    /**
     * Email the document to the client with the PDF attached.
     */
    public function send(Request $request, Document $document, IssueCreditNote $issueCreditNote): RedirectResponse
    {
        $this->ensureType($request, $document);

        abort_if(in_array($document->status, [DocumentStatus::Void, DocumentStatus::Invoiced], true), 403);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        // Sending a draft credit note issues it, so check it still fits the invoice first.
        if ($document->type === DocumentType::CreditNote && $document->status === DocumentStatus::Draft) {
            $issueCreditNote->handle($document, DocumentStatus::Sent);
        }

        Mail::to($validated['email'])->send(new DocumentMail($document, $validated['message']));

        $document->sent_at = now();
        if ($document->status === DocumentStatus::Draft) {
            $document->status = DocumentStatus::Sent;
        }
        $document->save();

        return back()->with('status', "Sent to {$validated['email']}.");
    }

    /**
     * Turn an estimate or a pro-forma invoice into an invoice. A pro-forma can be converted with the
     * payment the client made against it, which is recorded on the new invoice.
     */
    public function convert(Request $request, Document $document, ConvertToInvoice $convert): RedirectResponse
    {
        $this->ensureType($request, $document);

        abort_unless($document->type->convertsToInvoice(), 404);
        abort_if(in_array($document->status, [DocumentStatus::Invoiced, DocumentStatus::Declined, DocumentStatus::Void], true), 403);

        $payment = null;

        if ($document->type === DocumentType::ProForma && $request->boolean('paid')) {
            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:'.($document->total / 100)],
                'paid_on' => ['required', 'date', 'before_or_equal:today'],
                'method' => ['required', Rule::in(array_map(fn ($method) => $method->value, PaymentMethod::manual()))],
                'reference' => ['nullable', 'string', 'max:255'],
            ], [
                'amount.max' => 'That is more than the '.Money::format($document->total, $document->currency).' total.',
            ]);

            $payment = [...$validated, 'amount' => Money::toPence($validated['amount'])];
        }

        $document->business->ensureWithinLimit('invoices_per_month');

        $invoice = $convert->handle($document, $payment);

        if ($payment) {
            return to_route('invoices.show', $invoice)->with('status', "Invoice {$invoice->number} created from pro-forma {$document->number}, with the payment recorded.");
        }

        return to_route('invoices.edit', $invoice);
    }

    /**
     * Start a draft credit note for an issued invoice, with its lines to trim down to what's being credited.
     */
    public function creditNote(Request $request, Document $document, CopyDocument $copy): RedirectResponse
    {
        $this->ensureType($request, $document);

        abort_unless($document->type === DocumentType::Invoice, 404);
        abort_unless(in_array($document->status, [DocumentStatus::Sent, DocumentStatus::Paid], true), 403, 'Only sent or paid invoices can be credited. Edit or void a draft instead.');
        abort_if($document->amount_credited >= $document->total, 403, 'This invoice has already been credited in full.');

        $creditNote = $copy->handle($document, DocumentType::CreditNote);

        return to_route('creditnotes.edit', $creditNote);
    }

    /**
     * Start a draft delivery note from an estimate, invoice or pro-forma, with the same lines.
     */
    public function deliveryNote(Request $request, Document $document, CopyDocument $copy): RedirectResponse
    {
        $this->ensureType($request, $document);

        abort_unless($document->type->makesDeliveryNotes(), 404);
        abort_if($document->status === DocumentStatus::Void, 403);
        $document->business->ensureWithinLimit('deliverynotes_per_month');

        $deliveryNote = $copy->handle($document, DocumentType::DeliveryNote);

        return to_route('deliverynotes.edit', $deliveryNote);
    }

    /**
     * Record money paid back to the client against an issued credit note, for an invoice they'd already paid.
     */
    public function refund(Request $request, Document $document): RedirectResponse
    {
        $this->ensureType($request, $document);

        abort_unless($document->type === DocumentType::CreditNote, 404);

        $refundable = $document->refundable();
        abort_if($refundable === 0, 403, 'Nothing on this credit note is owed back to the client.');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:'.($refundable / 100)],
            'refunded_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(array_map(fn ($method) => $method->value, PaymentMethod::manual()))],
            'reference' => ['nullable', 'string', 'max:255'],
        ], [
            'amount.max' => 'That is more than the '.Money::format($refundable, $document->currency).' owed back to the client.',
        ]);

        $document->forceFill([
            'refund_amount' => Money::toPence($validated['amount']),
            'refunded_on' => $validated['refunded_on'],
            'refund_method' => $validated['method'],
            'refund_reference' => $validated['reference'] ?? null,
        ])->save();

        return back()->with('status', 'Refund recorded.');
    }

    public function destroyRefund(Request $request, Document $document): RedirectResponse
    {
        $this->ensureType($request, $document);

        abort_unless($document->type === DocumentType::CreditNote, 404);

        $document->forceFill(['refund_amount' => 0, 'refunded_on' => null, 'refund_method' => null, 'refund_reference' => null])->save();

        return back();
    }

    /**
     * The default email message for a document.
     */
    public static function defaultMessage(Document $document): string
    {
        $business = $document->business;
        $label = strtolower($document->type->label());
        $total = Money::format($document->total, $document->currency);

        $greeting = $document->client->contact_name ? "Hello {$document->client->contact_name}," : 'Hello,';

        $for = $document->type->showsPrices() ? " for {$total}" : '';

        $lines = [$greeting, '', $document->po_number
            ? "Please find attached {$label} {$document->number}{$for}, for your purchase order {$document->po_number}."
            : "Please find attached {$label} {$document->number}{$for}."];

        if ($document->type === DocumentType::CreditNote && $document->source) {
            $lines[] = "It credits invoice {$document->source->number}.";
        }

        if ($document->type->requestsPayment() && $document->due_date) {
            $lines[] = "Payment is due by {$document->due_date->format('j F Y')}.";
        }

        if ($document->type === DocumentType::ProForma) {
            $lines[] = 'This is a pro-forma invoice, not a VAT invoice. We will send you a full invoice once payment is received.';
        }

        $lines[] = '';
        $lines[] = $document->type === DocumentType::Invoice && $business->stripe_charges_enabled
            ? "View and pay online: {$document->publicUrl()}"
            : "View online: {$document->publicUrl()}";

        return implode("\n", [...$lines, '', 'Thank you,', $business->name]);
    }

    private function ensureType(Request $request, Document $document): void
    {
        abort_if($document->type !== DocumentType::from($request->route('type')), 404);
    }
}
