<?php

namespace Tests\Feature\Billing;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_page_is_displayed()
    {
        $this->actingAs(User::factory()->withBusiness()->create());

        $this->get('/products')->assertOk();
        $this->get('/products/create')->assertOk();
    }

    public function test_products_store_prices_in_pence()
    {
        $user = User::factory()->withBusiness()->create();
        $rate = $user->currentBusiness->taxRates()->where('is_default', true)->first();

        $this->actingAs($user)
            ->post('/products', ['name' => 'Consulting', 'unit_price' => '75.50', 'unit' => 'hour', 'tax_rate_id' => $rate->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/products');

        $product = Product::first();
        $this->assertSame(7550, $product->unit_price);
        $this->assertSame($rate->id, $product->tax_rate_id);
    }

    public function test_products_cannot_use_another_business_tax_rate()
    {
        $other = User::factory()->withBusiness()->create();
        $foreignRate = $other->currentBusiness->taxRates()->first();

        $this->actingAs(User::factory()->withBusiness()->create())
            ->post('/products', ['name' => 'Consulting', 'unit_price' => '10', 'tax_rate_id' => $foreignRate->id])
            ->assertSessionHasErrors('tax_rate_id');
    }

    public function test_prices_with_more_than_two_decimal_places_are_rejected()
    {
        $this->actingAs(User::factory()->withBusiness()->create())
            ->post('/products', ['name' => 'Consulting', 'unit_price' => '10.555'])
            ->assertSessionHasErrors('unit_price');
    }
}
