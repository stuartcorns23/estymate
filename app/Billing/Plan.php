<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * A subscription plan from config/plans.php.
 */
final class Plan
{
    /**
     * @param  array<string, int|null>  $limits
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly int $price,
        public readonly ?string $stripePrice,
        public readonly array $limits,
    ) {}

    public static function find(string $key): self
    {
        $plan = config("plans.plans.{$key}");

        if (! $plan) {
            throw new InvalidArgumentException("Unknown plan [{$key}].");
        }

        return new self($key, $plan['name'], $plan['price'], $plan['stripe_price'], $plan['limits']);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return array_map(fn (string $key) => self::find($key), array_keys(config('plans.plans')));
    }

    public static function forStripePrice(?string $priceId): ?self
    {
        foreach (self::all() as $plan) {
            if ($priceId !== null && $plan->stripePrice === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * The limit for a resource, or null when unlimited.
     */
    public function limit(string $resource): ?int
    {
        return $this->limits[$resource] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'price' => $this->price,
            'limits' => $this->limits,
            'purchasable' => $this->stripePrice !== null,
        ];
    }
}
