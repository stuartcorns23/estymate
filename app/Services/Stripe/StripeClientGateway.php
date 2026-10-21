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
        return $this->stripe->accounts->create([
            'type' => 'standard',
            'country' => 'GB',
            'email' => $email,
            'business_profile' => ['name' => $businessName],
        ])->id;
    }

    public function onboardingUrl(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->stripe->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ])->url;
    }

    public function chargesEnabled(string $accountId): bool
    {
        return (bool) $this->stripe->accounts->retrieve($accountId)->charges_enabled;
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
