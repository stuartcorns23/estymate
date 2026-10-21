<?php

namespace App\Http\Controllers;

use App\Actions\ConvertEstimateToInvoice;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Mail\DocumentMail;
use App\Models\Document;
use App\Support\DocumentPdf;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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
     * Turn an estimate into a draft invoice.
     */
    public function convert(Request $request, Document $document, ConvertEstimateToInvoice $convert): RedirectResponse
    {
        $this->ensureType($request, $document);

        abort_unless($document->type === DocumentType::Estimate, 404);
        abort_if(in_array($document->status, [DocumentStatus::Invoiced, DocumentStatus::Declined], true), 403);

        $invoice = $convert->handle($document);

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

        $lines = [$greeting, '', "Please find attached {$label} {$document->number} for {$total}."];

        if ($document->type === DocumentType::Invoice && $document->due_date) {
            $lines[] = "Payment is due by {$document->due_date->format('j F Y')}.";
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
