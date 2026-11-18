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
        // A delivery note may be handed to whoever takes the delivery, so its prices never leave the server.
        $prices = $document->type->showsPrices();

        return Inertia::render('public/document', [
            'document' => [
                'type' => $document->type->label(),
                'requests_payment' => $document->type->requestsPayment(),
                'is_pro_forma' => $document->type === DocumentType::ProForma,
                'shows_prices' => $prices,
                'credits_invoice' => $document->type === DocumentType::CreditNote ? $document->source?->number : null,
                'delivery_address' => $document->delivery_address,
                'number' => $document->number,
                'status' => $document->status->value,
                'is_overdue' => $document->isOverdue(),
                'issue_date' => $document->issue_date->toDateString(),
                'due_date' => $document->due_date?->toDateString(),
                'po_number' => $document->po_number,
                'currency' => $document->currency,
                'subtotal' => $prices ? $document->subtotal : 0,
                'tax_total' => $prices ? $document->tax_total : 0,
                'total' => $prices ? $document->total : 0,
                'amount_paid' => $document->amount_paid,
                'amount_credited' => $document->amount_credited,
                'balance_due' => $document->type === DocumentType::Invoice ? $document->balanceDue() : null,
                'notes' => $document->notes,
                'items' => $prices
                    ? $document->items->map->only('id', 'description', 'quantity', 'unit', 'unit_price', 'tax_rate', 'net_amount')
                    : $document->items->map->only('id', 'description', 'quantity', 'unit'),
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
            ->with(['business', 'client', 'items', 'source'])
            ->firstOrFail();
    }
}
