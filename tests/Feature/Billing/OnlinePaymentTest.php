<?php

namespace Tests\Feature\Billing;

use App\Enums\BusinessRole;
use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\DocumentActionController;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Services\Stripe\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeStripeGateway;
use Tests\TestCase;

class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_fake', 'services.stripe.key' => 'pk_test_fake']);
        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);

        $this->user = User::factory()->withBusiness()->create();
        $this->actingAs($this->user);
    }

    private function connectStripe(): void
    {
        $this->user->currentBusiness->forceFill(['stripe_account_id' => 'acct_test123', 'stripe_charges_enabled' => true])->save();
    }

    /**
     * A sent invoice for £120.
     */
    private function sentInvoice(): Document
    {
        $client = Client::factory()->create();

        $this->post('/invoices', [
            'client_id' => $client->id,
            'issue_date' => today()->toDateString(),
            'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => '100', 'tax_rate' => '20']],
        ])->assertSessionHasNoErrors();

        $invoice = Document::latest('id')->first();
        $invoice->forceFill(['status' => DocumentStatus::Sent])->save();

        return $invoice->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function webhook(Document $invoice, array $overrides = [], string $signature = 'valid')
    {
        $event = array_replace_recursive([
            'type' => 'checkout.session.completed',
            'account' => 'acct_test123',
            'data' => ['object' => [
                'payment_status' => 'paid',
                'payment_intent' => 'pi_123',
                'amount_total' => 12000,
                'metadata' => ['document_id' => (string) $invoice->id],
            ]],
        ], $overrides);

        return $this->call('POST', '/stripe/webhook', server: ['HTTP_STRIPE_SIGNATURE' => $signature], content: json_encode($event));
    }

    public function test_owners_can_connect_stripe()
    {
        $this->get('/settings/payments')->assertOk();

        $this->post('/settings/payments/connect')->assertRedirect('/settings/payments');
        $this->assertSame('acct_test123', $this->user->currentBusiness->fresh()->stripe_account_id);

        $this->postJson('/settings/payments/session')->assertOk()->assertJson(['client_secret' => 'accs_secret_acct_test123']);

        $this->post('/settings/payments/sync')->assertRedirect('/settings/payments');
        $this->assertTrue($this->user->currentBusiness->fresh()->stripe_charges_enabled);

        $this->get('/settings/payments')->assertInertia(fn ($page) => $page
            ->where('stripe.dashboard_url', 'https://dashboard.stripe.com/acct_test123'));
    }

    public function test_no_onboarding_session_before_connecting()
    {
        $this->postJson('/settings/payments/session')->assertNotFound();
    }

    public function test_members_cannot_connect_stripe()
    {
        $member = User::factory()->withBusiness(BusinessRole::Member)->create();
        $member->currentBusiness->forceFill(['stripe_account_id' => 'acct_member'])->save();

        $this->actingAs($member)->post('/settings/payments/connect')->assertForbidden();
        $this->actingAs($member)->postJson('/settings/payments/session')->assertForbidden();
    }

    public function test_clients_can_view_a_document_by_its_link_without_logging_in()
    {
        $invoice = $this->sentInvoice();
        auth()->logout();

        $this->get("/d/{$invoice->public_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('public/document')
                ->where('document.number', 'INV-0001')
                ->where('document.can_pay', false));

        $this->get("/d/{$invoice->public_token}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/d/not-a-real-token')->assertNotFound();
    }

    public function test_clients_can_pay_online_when_stripe_is_connected()
    {
        config(['services.stripe.application_fee_bps' => 100]);
        $this->connectStripe();
        $invoice = $this->sentInvoice();
        auth()->logout();

        $this->post("/d/{$invoice->public_token}/pay")->assertRedirect('https://checkout.stripe.test/session');

        $checkout = $this->stripe->checkouts[0];
        $this->assertSame('acct_test123', $checkout['accountId']);
        $this->assertSame(12000, $checkout['amount']);
        $this->assertSame(120, $checkout['applicationFee']);
        $this->assertSame((string) $invoice->id, $checkout['metadata']['document_id']);
    }

    public function test_paying_online_is_refused_without_stripe()
    {
        $invoice = $this->sentInvoice();

        $this->post("/d/{$invoice->public_token}/pay")->assertForbidden();
    }

    public function test_webhooks_record_the_payment_once()
    {
        $this->connectStripe();
        $invoice = $this->sentInvoice();
        auth()->logout();

        $this->webhook($invoice)->assertOk();
        $this->webhook($invoice)->assertOk();

        $invoice->refresh();
        $this->assertSame(DocumentStatus::Paid, $invoice->status);
        $this->assertSame(12000, $invoice->amount_paid);
        $this->assertCount(1, $invoice->payments);
        $this->assertSame(PaymentMethod::Stripe, $invoice->payments->first()->method);
        $this->assertSame($invoice->business_id, $invoice->payments->first()->business_id);
    }

    public function test_webhooks_from_another_account_are_ignored()
    {
        $this->connectStripe();
        $invoice = $this->sentInvoice();

        $this->webhook($invoice, ['account' => 'acct_someone_else'])->assertOk();

        $this->assertSame(0, $invoice->fresh()->amount_paid);
    }

    public function test_webhooks_with_a_bad_signature_are_rejected()
    {
        $invoice = $this->sentInvoice();

        $this->webhook($invoice, signature: 'forged')->assertStatus(400);
    }

    public function test_account_updates_switch_payments_on_and_off()
    {
        $this->connectStripe();

        $this->call('POST', '/stripe/webhook', server: ['HTTP_STRIPE_SIGNATURE' => 'valid'], content: json_encode([
            'type' => 'account.updated',
            'data' => ['object' => ['id' => 'acct_test123', 'capabilities' => ['card_payments' => 'inactive']]],
        ]))->assertOk();

        $this->assertFalse($this->user->currentBusiness->fresh()->stripe_charges_enabled);

        $this->call('POST', '/stripe/webhook', server: ['HTTP_STRIPE_SIGNATURE' => 'valid'], content: json_encode([
            'type' => 'account.updated',
            'data' => ['object' => ['id' => 'acct_test123', 'capabilities' => ['card_payments' => 'active']]],
        ]))->assertOk();

        $this->assertTrue($this->user->currentBusiness->fresh()->stripe_charges_enabled);
    }

    public function test_businesses_that_remove_estymate_from_stripe_are_disconnected()
    {
        $this->connectStripe();

        $this->call('POST', '/stripe/webhook', server: ['HTTP_STRIPE_SIGNATURE' => 'valid'], content: json_encode([
            'type' => 'account.application.deauthorized',
            'account' => 'acct_test123',
            'data' => ['object' => ['id' => 'ca_platform']],
        ]))->assertOk();

        $business = $this->user->currentBusiness->fresh();
        $this->assertNull($business->stripe_account_id);
        $this->assertFalse($business->stripe_charges_enabled);
    }

    public function test_emails_include_the_client_link()
    {
        $this->connectStripe();
        $invoice = $this->sentInvoice();

        $message = DocumentActionController::defaultMessage($invoice);

        $this->assertStringContainsString("View and pay online: http://localhost/d/{$invoice->public_token}", $message);
    }
}
