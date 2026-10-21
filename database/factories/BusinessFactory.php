<?php

namespace Database\Factories;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'email' => fake()->companyEmail(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
        ];
    }

    /**
     * Add the given user to the business with a role.
     */
    public function withUser(User $user, BusinessRole $role = BusinessRole::Owner): static
    {
        return $this->afterCreating(fn (Business $business) => $business->addUser($user, $role));
    }
}
