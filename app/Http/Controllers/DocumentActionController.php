<?php

namespace App\Http\Controllers;

use App\Actions\ConvertToInvoice;
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
    public function send(Request $request, Document $document): RedirectResponse
    {
        $this->ensureType($request, $document);

        abort_if(in_array($document->status, [DocumentStatus::Void, DocumentStatus::Invoiced], true), 403);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

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
     * The default email message for a document.
     */
    public static function defaultMessage(Document $document): string
    {
        $business = $document->business;
        $label = strtolower($document->type->label());
        $total = Money::format($document->total, $document->currency);

        $greeting = $document->client->contact_name ? "Hello {$document->client->contact_name}," : 'Hello,';

        $lines = [$greeting, '', $document->po_number
            ? "Please find attached {$label} {$document->number} for {$total}, for your purchase order {$document->po_number}."
            : "Please find attached {$label} {$document->number} for {$total}."];

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
