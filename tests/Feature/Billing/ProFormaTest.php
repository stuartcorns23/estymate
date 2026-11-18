<?php

namespace Tests\Feature\Billing;

use App\Enums\DocumentLayout;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Mail\DocumentMail;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Support\DocumentDesign;
use App\Support\DocumentPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProFormaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-18');
        $this->user = User::factory()->withBusiness()->create();
        $this->actingAs($this->user);
        $this->client = Client::factory()->create(['email' => 'client@example.com']);
    }

    private function createProForma(): Document
    {
        $this->post('/proformas', [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-18',
            'due_date' => '2026-11-25',
            'items' => [
                ['description' => 'Deposit for kitchen fit', 'quantity' => '1', 'unit_price' => '500.00', 'tax_rate' => '20.00'],
            ],
        ])->assertSessionHasNoErrors();

        return Document::ofType(DocumentType::ProForma)->latest('id')->first();
    }

    public function test_pages_are_displayed()
    {
        $proForma = $this->createProForma();

        foreach (['/proformas', '/proformas/create', "/proformas/{$proForma->id}", "/proformas/{$proForma->id}/edit"] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get("/proformas/{$proForma->id}")->assertInertia(fn (Assert $page) => $page
            ->component('documents/show')
            ->where('type.label', 'Pro-forma invoice')
            ->where('type.converts_to_invoice', true));
    }

    public function test_pro_formas_have_their_own_numbering()
    {
        $this->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-18',
            'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '20.00']],
        ]);

        $first = $this->createProForma();
        $second = $this->createProForma();

        $this->assertSame('PF-0001', $first->number);
        $this->assertSame('PF-0002', $second->number);
        $this->assertSame(60000, $first->total);
        $this->assertSame('INV-0001', Document::ofType(DocumentType::Invoice)->first()->number);

        $this->user->currentBusiness->update(['proforma_prefix' => 'PRO-']);
        $this->assertSame('PRO-0003', $this->createProForma()->number);
    }

    public function test_pro_formas_cannot_take_payments_or_be_marked_paid()
    {
        $proForma = $this->createProForma();

        $this->post("/proformas/{$proForma->id}/payments", ['amount' => '600.00'])->assertNotFound();
        $this->patch("/proformas/{$proForma->id}/status", ['status' => 'paid'])->assertSessionHasErrors('status');
        $this->get("/invoices/{$proForma->id}")->assertNotFound();
    }

    public function test_pro_formas_convert_to_a_draft_invoice()
    {
        $proForma = $this->createProForma();

        $response = $this->post("/proformas/{$proForma->id}/convert");

        $invoice = Document::ofType(DocumentType::Invoice)->first();
        $response->assertRedirect("/invoices/{$invoice->id}/edit");

        $this->assertSame('INV-0001', $invoice->number);
        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        $this->assertSame($proForma->id, $invoice->source_document_id);
        $this->assertSame(60000, $invoice->total);
        $this->assertCount(1, $invoice->items);
        $this->assertSame(DocumentStatus::Invoiced, $proForma->fresh()->status);

        $this->post("/proformas/{$proForma->id}/convert")->assertForbidden();
        $this->get("/invoices/{$invoice->id}")->assertInertia(fn (Assert $page) => $page
            ->where('document.source.label', 'pro-forma invoice')
            ->where('document.source.number', 'PF-0001'));
    }

    public function test_paid_pro_formas_convert_to_a_paid_invoice()
    {
        $proForma = $this->createProForma();

        $response = $this->post("/proformas/{$proForma->id}/convert", [
            'paid' => true,
            'amount' => '600.00',
            'paid_on' => '2026-11-17',
            'method' => 'bank_transfer',
            'reference' => 'BACS 123',
        ]);

        $invoice = Document::ofType(DocumentType::Invoice)->first();
        $response->assertRedirect("/invoices/{$invoice->id}");

        $this->assertSame(DocumentStatus::Paid, $invoice->status);
        $this->assertSame(60000, $invoice->amount_paid);
        $this->assertSame('2026-11-18', $invoice->issue_date->toDateString());
        $this->assertSame('BACS 123', $invoice->payments->first()->reference);
        $this->assertSame(0, $proForma->fresh()->amount_paid);
    }

    public function test_a_part_payment_leaves_the_invoice_unpaid()
    {
        $proForma = $this->createProForma();

        $this->post("/proformas/{$proForma->id}/convert", [
            'paid' => true, 'amount' => '200.00', 'paid_on' => '2026-11-18', 'method' => 'cash',
        ])->assertSessionHasNoErrors();

        $invoice = Document::ofType(DocumentType::Invoice)->first();
        $this->assertSame(DocumentStatus::Sent, $invoice->status);
        $this->assertSame(40000, $invoice->balanceDue());
    }

    public function test_the_payment_cannot_be_more_than_the_pro_forma_total()
    {
        $proForma = $this->createProForma();

        $this->post("/proformas/{$proForma->id}/convert", [
            'paid' => true, 'amount' => '600.01', 'paid_on' => '2026-11-18', 'method' => 'cash',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Document::ofType(DocumentType::Invoice)->count());
        $this->assertSame(DocumentStatus::Draft, $proForma->fresh()->status);
    }

    public function test_void_pro_formas_cannot_be_converted()
    {
        $proForma = $this->createProForma();
        $this->patch("/proformas/{$proForma->id}/status", ['status' => 'void']);

        $this->post("/proformas/{$proForma->id}/convert")->assertForbidden();
    }

    public function test_every_layout_titles_the_pdf_as_a_pro_forma_that_is_not_a_vat_invoice()
    {
        $proForma = $this->createProForma();

        foreach (DocumentLayout::cases() as $layout) {
            $html = DocumentPdf::html($proForma->fresh(), new DocumentDesign(layout: $layout));

            $this->assertMatchesRegularExpression('/Pro-forma invoice|PRO-FORMA INVOICE/', $html, $layout->value);
            $this->assertStringContainsString('not a VAT invoice', $html, $layout->value);
            $this->assertStringNotContainsString('Tax point', $html, $layout->value);
            $this->assertStringContainsString('Payment due', $html, $layout->value);
        }

        $response = $this->get("/proformas/{$proForma->id}/pdf");
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('Pro-forma invoice PF-0001.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_pro_formas_can_be_emailed()
    {
        Mail::fake();
        $proForma = $this->createProForma();

        $this->get("/proformas/{$proForma->id}")->assertInertia(fn (Assert $page) => $page
            ->where('document.email_message', fn (string $message) => str_contains($message, 'pro-forma invoice PF-0001')
                && str_contains($message, 'Payment is due by 25 November 2026')
                && str_contains($message, 'not a VAT invoice')));

        $this->post("/proformas/{$proForma->id}/send", ['email' => 'client@example.com', 'message' => 'Please pay upfront'])
            ->assertSessionHasNoErrors();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail) => $mail->hasTo('client@example.com')
            && $mail->envelope()->subject === "Pro-forma invoice PF-0001 from {$this->user->currentBusiness->name}");

        $this->assertSame(DocumentStatus::Sent, $proForma->fresh()->status);
    }

    public function test_pro_formas_stay_out_of_income_and_tax_figures()
    {
        $business = $this->user->currentBusiness;
        $business->forceFill(['plan_override' => 'pro', 'vat_number' => 'GB123456789'])->save();

        $proForma = $this->createProForma();
        $proForma->update(['issue_date' => '2025-05-01']);
        $proForma->forceFill(['status' => DocumentStatus::Sent])->save();

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('totals.outstanding', 0)
            ->where('totals.overdue', 0)
            ->where('totals.drafts', 0)
            ->where('usage.proformas_this_month', 1));

        foreach (['cash', 'accruals'] as $basis) {
            $this->get("/tax-return?year=2025&basis={$basis}")->assertInertia(fn (Assert $page) => $page
                ->where('report.turnover', 0)
                ->where('report.vat.output', 0));
        }
    }

    public function test_pro_formas_have_their_own_monthly_plan_limit()
    {
        $this->user->currentBusiness->forceFill(['plan_override' => 'free'])->save();

        for ($i = 0; $i < 5; $i++) {
            $this->createProForma();
        }

        $this->get('/proformas/create')->assertRedirect(route('billing.edit'));
        $this->post('/proformas', [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-18',
            'items' => [['description' => 'Deposit', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '20.00']],
        ])
            ->assertRedirect(route('billing.edit'))
            ->assertSessionHas('status', 'The Free plan includes 5 pro-forma invoices a month. Upgrade to add more.');

        // Invoices have their own allowance, and converting uses it.
        $this->get('/invoices/create')->assertOk();
        $this->post('/proformas/'.Document::ofType(DocumentType::ProForma)->first()->id.'/convert')->assertRedirect();
        $this->assertSame(1, $this->user->currentBusiness->usage()['invoices_this_month']);
    }
}
