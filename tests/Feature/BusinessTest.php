<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_page_is_displayed()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/businesses/create')->assertOk();
    }

    public function test_users_can_create_a_business_with_uk_defaults()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/businesses', [
            'name' => 'Acme Ltd',
            'vat_number' => 'GB123456789',
            'company_number' => '01234567',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/dashboard');

        $business = $user->refresh()->currentBusiness;

        $this->assertSame('Acme Ltd', $business->name);
        $this->assertSame('GBP', $business->currency);
        $this->assertSame('GB', $business->country);
        $this->assertSame(BusinessRole::Owner, $user->roleIn($business));
        $this->assertEqualsCanonicalizing(
            ['20.00', '5.00', '0.00'],
            $business->documentTaxRates()->pluck('rate')->all(),
        );
        $this->assertSame('20.00', $business->documentTaxRates()->where('is_default', true)->value('rate'));
    }

    public function test_invalid_vat_numbers_are_rejected()
    {
        $this->actingAs(User::factory()->create())
            ->post('/businesses', ['name' => 'Acme Ltd', 'vat_number' => 'FR123'])
            ->assertSessionHasErrors('vat_number');
    }

    public function test_owners_can_update_business_details()
    {
        $user = User::factory()->withBusiness()->create();

        $this->actingAs($user)
            ->patch('/settings/business', ['name' => 'Renamed Ltd', 'postcode' => 'SW1A 1AA'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/business');

        $this->assertSame('Renamed Ltd', $user->currentBusiness->fresh()->name);
    }

    public function test_members_cannot_update_business_details()
    {
        $user = User::factory()->withBusiness(BusinessRole::Member)->create();

        $this->actingAs($user)
            ->patch('/settings/business', ['name' => 'Renamed Ltd'])
            ->assertForbidden();
    }

    public function test_users_can_switch_between_their_businesses()
    {
        $user = User::factory()->withBusiness()->create();
        $other = Business::factory()->withUser($user, BusinessRole::Member)->create();

        $this->actingAs($user)
            ->put('/current-business', ['business_id' => $other->id])
            ->assertRedirect('/dashboard');

        $this->assertSame($other->id, $user->fresh()->current_business_id);
    }

    public function test_users_cannot_switch_to_a_business_they_do_not_belong_to()
    {
        $user = User::factory()->withBusiness()->create();
        $stranger = Business::factory()->create();

        $this->actingAs($user)
            ->put('/current-business', ['business_id' => $stranger->id])
            ->assertForbidden();
    }
}
