<?php

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = fake()->numberBetween(1000, 100000);

        return [
            'supplier' => fake()->company(),
            'date' => fake()->dateTimeBetween('-3 months')->format('Y-m-d'),
            'category' => fake()->randomElement(ExpenseCategory::cases()),
            'total' => $total,
            'vat_amount' => intdiv($total, 6),
        ];
    }
}
