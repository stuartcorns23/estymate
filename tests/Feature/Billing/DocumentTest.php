<?php

namespace Tests\Feature\Billing;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Mail\DocumentMail;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withBusiness()->create();
        $this->actingAs($this->user);
        $this->client = Client::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'client_id' => $this->client->id,
            'issue_date' => '2026-10-21',
            'due_date' => '2026-11-20',
            'items' => [
                ['description' => 'Design', 'quantity' => '2.5', 'unit_price' => '100.00', 'tax_rate' => '20.00'],
                ['description' => 'Childrens car seat', 'quantity' => '1', 'unit_price' => '49.99', 'tax_rate' => '5.00'],
                ['description' => 'Book', 'quantity' => '3', 'unit_price' => '9.99', 'tax_rate' => '0.00'],
            ],
            ...$overrides,
        ];
    }

    private function createInvoice(): Document
    {
        $this->post('/invoices', $this->payload())->assertSessionHasNoErrors();

        return Document::latest('id')->first();
    }

    public function test_pages_are_displayed()
    {
        $invoice = $this->createInvoice();

        foreach (['/estimates', '/estimates/create', '/invoices', '/invoices/create', "/invoices/{$invoice->id}", "/invoices/{$invoice->id}/edit"] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_invoices_are_created_with_vat_totals_in_pence()
    {
        $invoice = $this->createInvoice();

        // 2.5 × £100 at 20% + £49.99 at 5% + 3 × £9.99 at 0%
        $this->assertSame(DocumentType::Invoice, $invoice->type);
        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        $this->assertSame(25000 + 4999 + 2997, $invoice->subtotal);
        $this->assertSame(5000 + 250, $invoice->tax_total);
        $this->assertSame(32996 + 5250, $invoice->total);
        $this->assertCount(3, $invoice->items);
        $this->assertSame('INV-0001', $invoice->number);
        $this->assertSame($this->user->current_business_id, $invoice->business_id);
    }

    public function test_numbers_increase_per_business_and_type()
    {
        $this->post('/invoices', $this->payload());
        $this->post('/invoices', $this->payload());
        $this->post('/estimates', $this->payload());

        $this->assertSame(['INV-0001', 'INV-0002'], Document::ofType(DocumentType::Invoice)->orderBy('id')->pluck('number')->all());
        $this->assertSame('EST-0001', Document::ofType(DocumentType::Estimate)->value('number'));

        $this->actingAs(User::factory()->withBusiness()->create());
        $this->client = Client::factory()->create();
        $this->post('/invoices', $this->payload());

        $this->assertSame('INV-0001', Document::latest('id')->value('number'));
    }

    public function test_line_items_are_validated()
    {
        $this->post('/invoices', $this->payload(['items' => []]))->assertSessionHasErrors('items');

        $this->post('/invoices', $this->payload(['items' => [
            ['description' => '', 'quantity' => '0', 'unit_price' => '-1', 'tax_rate' => '17.5'],
        ]]))->assertSessionHasErrors(['items.0.description', 'items.0.quantity', 'items.0.unit_price', 'items.0.tax_rate']);
    }

    public function test_clients_from_another_business_cannot_be_invoiced()
    {
        $foreignClient = Client::withoutGlobalScopes()->forceCreate([
            'name' => 'Not yours',
            'business_id' => User::factory()->withBusiness()->create()->current_business_id,
        ]);

        $this->post('/invoices', $this->payload(['client_id' => $foreignClient->id]))->assertSessionHasErrors('client_id');
    }

    public function test_invoices_can_be_edited_until_paid()
    {
        $invoice = $this->createInvoice();

        $this->put("/invoices/{$invoice->id}", $this->payload(['items' => [
            ['description' => 'Design', 'quantity' => '1', 'unit_price' => '10', 'tax_rate' => '20'],
        ]]))->assertRedirect("/invoices/{$invoice->id}");

        $this->assertSame(1200, $invoice->fresh()->total);

        $this->patch("/invoices/{$invoice->id}/status", ['status' => 'sent'])->assertRedirect();
        $this->post("/invoices/{$invoice->id}/payments", ['amount' => '12.00', 'paid_on' => today()->toDateString(), 'method' => 'bank_transfer'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($invoice->fresh()->paid_at);

        $this->put("/invoices/{$invoice->id}", $this->payload())->assertForbidden();
    }

    public function test_estimate_routes_do_not_show_invoices()
    {
        $invoice = $this->createInvoice();

        $this->get("/estimates/{$invoice->id}")->assertNotFound();
    }

    public function test_statuses_are_limited_to_the_document_type()
    {
        $invoice = $this->createInvoice();

        $this->patch("/invoices/{$invoice->id}/status", ['status' => 'accepted'])->assertSessionHasErrors('status');
    }

    public function test_only_drafts_can_be_deleted()
    {
        $invoice = $this->createInvoice();
        $invoice->status = DocumentStatus::Sent;
        $invoice->save();

        $this->delete("/invoices/{$invoice->id}")->assertForbidden();

        $draft = $this->createInvoice();
        $this->delete("/invoices/{$draft->id}")->assertRedirect('/invoices');
        $this->assertSoftDeleted($draft);
    }

    public function test_estimates_can_be_converted_to_invoices()
    {
        $this->post('/estimates', $this->payload())->assertSessionHasNoErrors();
        $estimate = Document::ofType(DocumentType::Estimate)->first();

        $response = $this->post("/estimates/{$estimate->id}/convert");

        $invoice = Document::ofType(DocumentType::Invoice)->first();
        $response->assertRedirect("/invoices/{$invoice->id}/edit");

        $this->assertSame(DocumentStatus::Invoiced, $estimate->fresh()->status);
        $this->assertSame($estimate->id, $invoice->source_document_id);
        $this->assertSame('INV-0001', $invoice->number);
        $this->assertSame($estimate->total, $invoice->total);
        $this->assertCount(3, $invoice->items);

        $this->post("/estimates/{$estimate->id}/convert")->assertForbidden();
    }

    public function test_pdfs_can_be_downloaded()
    {
        $invoice = $this->createInvoice();

        $response = $this->get("/invoices/{$invoice->id}/pdf");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_documents_can_be_emailed_to_clients()
    {
        Mail::fake();
        $invoice = $this->createInvoice();

        $this->post("/invoices/{$invoice->id}/send", ['email' => 'client@example.com', 'message' => 'Please pay'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail) => $mail->hasTo('client@example.com')
            && $mail->document->is($invoice)
            && count($mail->attachments()) === 1);

        $invoice->refresh();
        $this->assertSame(DocumentStatus::Sent, $invoice->status);
        $this->assertNotNull($invoice->sent_at);
    }

    public function test_overdue_invoices_can_be_filtered()
    {
        $invoice = $this->createInvoice();
        $invoice->status = DocumentStatus::Sent;
        $invoice->due_date = now()->subDay();
        $invoice->save();
        $this->createInvoice();

        $this->get('/invoices?status=overdue')
            ->assertInertia(fn ($page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.is_overdue', true));
    }

    public function test_usage_is_counted_per_business()
    {
        $this->createInvoice();
        $this->createInvoice()->delete();
        $this->post('/estimates', $this->payload());
        Client::factory()->create();

        $this->assertSame(
            ['clients' => 2, 'estimates_this_month' => 1, 'invoices_this_month' => 2],
            $this->user->currentBusiness->usage(),
        );
    }
}
