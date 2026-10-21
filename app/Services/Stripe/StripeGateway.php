<?php

namespace App\Services\Stripe;

/**
 * The Stripe calls Estymate makes, kept behind an interface so tests can fake them.
 */
interface StripeGateway
{
    /**
     * Create a connected Stripe account for a business and return its id.
     */
    public function createAccount(string $email, string $businessName): string;

    /**
     * A one-time onboarding URL for the connected account.
     */
    public function onboardingUrl(string $accountId, string $refreshUrl, string $returnUrl): string;

    /**
     * Whether the connected account can take card payments yet.
     */
    public function chargesEnabled(string $accountId): bool;

    /**
     * Create a Checkout session on the connected account and return its URL.
     *
     * @param  array<string, string>  $metadata
     */
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
    ): string;

    /**
     * Verify a webhook payload's signature and return the decoded event.
     *
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException when the signature or payload is invalid
     */
    public function verifyWebhook(string $payload, string $signature): array;
}
