<?php

namespace Tests\Feature\Billing;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        config(['plans.plans.starter.stripe_price' => 'price_starter', 'plans.plans.pro.stripe_price' => 'price_pro']);

        $this->user = User::factory()->withBusiness()->create();
        $this->business = $this->user->currentBusiness;
        $this->actingAs($this->user);
    }

    private function endTrial(): void
    {
        $this->business->forceFill(['trial_ends_at' => now()->subDay()])->save();
    }

    private function subscribeTo(string $price, string $status = 'active', $endsAt = null): void
    {
        $this->business->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => $status,
            'stripe_price' => $price,
            'quantity' => 1,
            'ends_at' => $endsAt,
        ]);
    }

    public function test_cashier_sees_no_subscription_tax_rates()
    {
        // Cashier calls taxRates() when building a subscription checkout; it must stay Cashier's.
        $this->assertSame([], $this->business->taxRates());
    }

    public function test_new_businesses_start_on_a_pro_trial()
    {
        $this->assertSame('pro', $this->business->plan()->key);
        $this->assertTrue($this->business->isOnFreeTrial());
        $this->assertTrue($this->business->trial_ends_at->between(now()->addDays(13), now()->addDays(15)));
    }

    public function test_businesses_drop_to_free_after_the_trial()
    {
        $this->endTrial();

        $this->assertSame('free', $this->business->fresh()->plan()->key);
    }

    public function test_subscriptions_set_the_plan()
    {
        $this->endTrial();
        $this->subscribeTo('price_starter');

        $this->assertSame('starter', $this->business->fresh()->plan()->key);
        $this->assertFalse($this->business->fresh()->isOnFreeTrial());
    }

    public function test_ended_subscriptions_fall_back_to_free()
    {
        $this->endTrial();
        $this->subscribeTo('price_pro', 'canceled', now()->subDay());

        $this->assertSame('free', $this->business->fresh()->plan()->key);
    }

    public function test_comped_plans_win()
    {
        $this->endTrial();

        $this->artisan('business:comp', ['business' => $this->business->id, 'plan' => 'pro'])->assertSuccessful();
        $this->assertSame('pro', $this->business->fresh()->plan()->key);

        $this->artisan('business:comp', ['business' => $this->business->id])->assertSuccessful();
        $this->assertSame('free', $this->business->fresh()->plan()->key);
    }

    public function test_client_limit_sends_people_to_billing()
    {
        $this->endTrial();
        Client::factory()->count(5)->create();

        $this->get('/clients/create')->assertRedirect('/settings/billing');
        $this->post('/clients', ['name' => 'One too many'])
            ->assertRedirect('/settings/billing')
            ->assertSessionHas('status', 'The Free plan includes 5 clients. Upgrade to add more.');

        $this->assertSame(5, Client::count());
    }

    public function test_monthly_invoice_limit_is_enforced_and_resets_next_month()
    {
        $this->endTrial();
        $client = Client::factory()->create();
        $payload = [
            'client_id' => $client->id,
            'issue_date' => today()->toDateString(),
            'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => '10', 'tax_rate' => '20']],
        ];

        foreach (range(1, 5) as $i) {
            $this->post('/invoices', $payload)->assertSessionHasNoErrors();
        }

        $this->post('/invoices', $payload)->assertRedirect('/settings/billing');
        $this->post('/estimates', $payload)->assertSessionHasNoErrors();

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth());
        $this->post('/invoices', $payload)->assertSessionHasNoErrors();
    }

    public function test_team_limit_counts_pending_invitations()
    {
        $this->endTrial();
        $this->subscribeTo('price_starter');

        $this->post('/settings/team/invitations', ['email' => 'one@example.com', 'role' => 'member'])->assertSessionHasNoErrors();
        $this->post('/settings/team/invitations', ['email' => 'two@example.com', 'role' => 'member'])->assertRedirect('/settings/billing');
    }

    public function test_billing_page_shows_plan_and_usage()
    {
        Client::factory()->count(2)->create();

        $this->get('/settings/billing')->assertInertia(fn ($page) => $page
            ->component('settings/billing')
            ->where('plan.key', 'pro')
            ->where('usage.clients', 2)
            ->has('plans', 3)
            ->where('canManage', true));
    }

    public function test_members_cannot_start_a_subscription()
    {
        $member = User::factory()->withBusiness(BusinessRole::Member)->create();

        $this->actingAs($member)->post('/settings/billing/checkout/starter')->assertForbidden();
    }

    public function test_home_page_lists_plans()
    {
        auth()->logout();

        $this->get('/')->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->has('plans', 3)
            ->where('plans.1.name', 'Starter'));
    }
}
