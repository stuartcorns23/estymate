<?php

namespace Tests\Feature;

use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_only_see_records_from_their_current_business()
    {
        $user = User::factory()->withBusiness()->create();
        $otherUser = User::factory()->withBusiness()->create();

        $this->actingAs($user);

        $this->assertCount(3, TaxRate::all());
        $this->assertTrue(TaxRate::all()->every(fn (TaxRate $rate) => $rate->business_id === $user->current_business_id));
        $this->assertNull(TaxRate::find($otherUser->currentBusiness->taxRates()->first()->id));
    }

    public function test_new_records_belong_to_the_current_business()
    {
        $user = User::factory()->withBusiness()->create();

        $this->actingAs($user);

        $rate = TaxRate::create(['name' => 'Custom', 'rate' => '12.50']);

        $this->assertSame($user->current_business_id, $rate->business_id);
    }
}
