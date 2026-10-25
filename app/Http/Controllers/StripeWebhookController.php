<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Business;
use App\Models\Document;
use App\Models\Payment;
use App\Services\Stripe\StripeGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    /**
     * Handle events from businesses' connected Stripe accounts.
     */
    public function __invoke(Request $request, StripeGateway $stripe): Response
    {
        try {
            $event = $stripe->verifyWebhook($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (UnexpectedValueException) {
            return response('Invalid signature', 400);
        }

        match ($event['type'] ?? null) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->recordPayment($event),
            'account.updated' => $this->updateAccount($event),
            'account.application.deauthorized' => $this->disconnectAccount($event),
            default => null,
        };

        return response('OK');
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function recordPayment(array $event): void
    {
        $session = $event['data']['object'];

        if (($session['payment_status'] ?? null) !== 'paid') {
            return;
        }

        $document = Document::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereKey($session['metadata']['document_id'] ?? 0)
            ->with('business')
            ->first();

        // Only trust the event if it came from the account belonging to the invoice's business.
        if (! $document || ! $document->business->stripe_account_id || $document->business->stripe_account_id !== ($event['account'] ?? null)) {
            return;
        }

        DB::transaction(function () use ($document, $session) {
            $intentId = $session['payment_intent'];

            // Stripe can deliver the same event more than once.
            if (Payment::withoutGlobalScopes()->where('stripe_payment_intent_id', $intentId)->exists()) {
                return;
            }

            $payment = new Payment([
                'amount' => (int) $session['amount_total'],
                'paid_on' => today(),
                'method' => PaymentMethod::Stripe,
                'reference' => $intentId,
                'stripe_payment_intent_id' => $intentId,
            ]);
            $payment->business_id = $document->business_id;
            $document->payments()->save($payment);
            $document->refreshPayments();
        });
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function updateAccount(array $event): void
    {
        $account = $event['data']['object'];

        Business::where('stripe_account_id', $account['id'] ?? null)
            ->update(['stripe_charges_enabled' => ($account['capabilities']['card_payments'] ?? null) === 'active']);
    }

    /**
     * The business removed Estymate from their Stripe account, so we can no longer charge on it.
     *
     * @param  array<string, mixed>  $event
     */
    private function disconnectAccount(array $event): void
    {
        if (! isset($event['account'])) {
            return;
        }

        Business::where('stripe_account_id', $event['account'])
            ->update(['stripe_account_id' => null, 'stripe_charges_enabled' => false]);
    }
}
