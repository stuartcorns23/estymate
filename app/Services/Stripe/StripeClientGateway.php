<?php

namespace App\Services\Stripe;

use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeClientGateway implements StripeGateway
{
    public function __construct(private StripeClient $stripe, private ?string $webhookSecret) {}

    public function createAccount(string $email, string $businessName): string
    {
        // Accounts v2 with the merchant configuration: the business pays Stripe's fees,
        // Stripe carries loss liability and they get the full Stripe Dashboard.
        return $this->stripe->v2->core->accounts->create([
            'contact_email' => $email,
            'display_name' => $businessName,
            'dashboard' => 'full',
            'identity' => ['country' => 'GB'],
            'defaults' => [
                'currency' => 'gbp',
                'responsibilities' => ['fees_collector' => 'stripe', 'losses_collector' => 'stripe'],
            ],
            'configuration' => [
                'merchant' => ['capabilities' => ['card_payments' => ['requested' => true]]],
            ],
        ])->id;
    }

    public function accountSessionSecret(string $accountId): string
    {
        return $this->stripe->accountSessions->create([
            'account' => $accountId,
            'components' => [
                'account_onboarding' => ['enabled' => true],
                'notification_banner' => ['enabled' => true],
            ],
        ])->client_secret;
    }

    public function chargesEnabled(string $accountId): bool
    {
        $account = $this->stripe->v2->core->accounts->retrieve($accountId, ['include' => ['configuration.merchant']]);

        return $account->configuration?->merchant?->capabilities?->card_payments?->status === 'active';
    }

    public function checkoutUrl(
        string $accountId,
        int $amount,
        string $currency,
        string $description,
        ?string $customerEmail,
        int $applicationFee,
        array $metadata,
        string $successUrl,
        string $cancelUrl,
    ): string {
        $paymentIntent = ['metadata' => $metadata];

        if ($applicationFee > 0) {
            $paymentIntent['application_fee_amount'] = $applicationFee;
        }

        return $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $amount,
                    'product_data' => ['name' => $description],
                ],
            ]],
            'customer_email' => $customerEmail,
            'payment_intent_data' => $paymentIntent,
            'metadata' => $metadata,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ], ['stripe_account' => $accountId])->url;
    }

    public function verifyWebhook(string $payload, string $signature): array
    {
        if (! $this->webhookSecret) {
            throw new UnexpectedValueException('STRIPE_CONNECT_WEBHOOK_SECRET is not set.');
        }

        try {
            return Webhook::constructEvent($payload, $signature, $this->webhookSecret)->toArray();
        } catch (SignatureVerificationException $e) {
            throw new UnexpectedValueException($e->getMessage(), previous: $e);
        }
    }
}
