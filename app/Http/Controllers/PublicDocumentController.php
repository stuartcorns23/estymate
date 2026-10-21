<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\Stripe\StripeGateway;
use App\Support\DocumentPdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Pages clients reach from the link in their email. They have no account, so the token is the key.
 */
class PublicDocumentController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $document = $this->find($token);

        return Inertia::render('public/document', [
            'document' => [
                'type' => $document->type->label(),
                'number' => $document->number,
                'status' => $document->status->value,
                'is_overdue' => $document->isOverdue(),
                'issue_date' => $document->issue_date->toDateString(),
                'due_date' => $document->due_date?->toDateString(),
                'currency' => $document->currency,
                'subtotal' => $document->subtotal,
                'tax_total' => $document->tax_total,
                'total' => $document->total,
                'amount_paid' => $document->amount_paid,
                'balance_due' => $document->type === DocumentType::Invoice ? $document->balanceDue() : null,
                'notes' => $document->notes,
                'items' => $document->items->map->only('id', 'description', 'quantity', 'unit', 'unit_price', 'tax_rate', 'net_amount'),
                'business' => $document->business->only('name', 'email', 'phone'),
                'client_name' => $document->client->name,
                'can_pay' => $document->canBePaidOnline(),
                'pdf_url' => route('public.documents.pdf', $token),
                'pay_url' => route('public.documents.pay', $token),
            ],
            'justPaid' => $request->boolean('paid'),
        ]);
    }

    public function pdf(string $token): HttpResponse
    {
        $document = $this->find($token);

        return response(DocumentPdf::render($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.DocumentPdf::filename($document).'"',
        ]);
    }

    /**
     * Send the client to Stripe Checkout for the balance due.
     */
    public function pay(string $token, StripeGateway $stripe): HttpResponse
    {
        $document = $this->find($token);

        abort_unless($document->canBePaidOnline(), 403, 'This invoice cannot be paid online.');

        $amount = $document->balanceDue();
        $feeBasisPoints = (int) config('services.stripe.application_fee_bps');

        return Inertia::location($stripe->checkoutUrl(
            accountId: $document->business->stripe_account_id,
            amount: $amount,
            currency: $document->currency,
            description: "Invoice {$document->number} from {$document->business->name}",
            customerEmail: $document->client->email,
            applicationFee: intdiv($amount * $feeBasisPoints, 10000),
            metadata: [
                'document_id' => (string) $document->id,
                'business_id' => (string) $document->business_id,
            ],
            successUrl: route('public.documents.show', ['token' => $token, 'paid' => 1]),
            cancelUrl: route('public.documents.show', $token),
        ));
    }

    private function find(string $token): Document
    {
        return Document::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('public_token', $token)
            ->with(['business', 'client', 'items'])
            ->firstOrFail();
    }
}
