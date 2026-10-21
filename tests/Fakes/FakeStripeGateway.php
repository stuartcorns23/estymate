<?php

namespace Tests\Fakes;

use App\Services\Stripe\StripeGateway;
use UnexpectedValueException;

class FakeStripeGateway implements StripeGateway
{
    public bool $chargesEnabled = true;

    /** @var list<array<string, mixed>> */
    public array $checkouts = [];

    public function createAccount(string $email, string $businessName): string
    {
        return 'acct_test123';
    }

    public function onboardingUrl(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return "https://connect.stripe.test/onboarding/{$accountId}";
    }

    public function chargesEnabled(string $accountId): bool
    {
        return $this->chargesEnabled;
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
        $this->checkouts[] = compact('accountId', 'amount', 'currency', 'applicationFee', 'metadata');

        return 'https://checkout.stripe.test/session';
    }

    public function verifyWebhook(string $payload, string $signature): array
    {
        if ($signature !== 'valid') {
            throw new UnexpectedValueException('Bad signature');
        }

        return json_decode($payload, true);
    }
}
