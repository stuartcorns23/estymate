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

class CreditNoteTest extends TestCase
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

    /**
     * A sent invoice for £600 (£500 + £100 VAT) over two lines.
     */
    private function createInvoice(): Document
    {
        $this->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-10',
            'due_date' => '2026-12-10',
            'po_number' => 'PO-77',
            'items' => [
                ['description' => 'Kitchen units', 'quantity' => '1', 'unit_price' => '400.00', 'tax_rate' => '20.00'],
                ['description' => 'Fitting', 'quantity' => '2', 'unit_price' => '50.00', 'tax_rate' => '20.00'],
            ],
        ])->assertSessionHasNoErrors();

        $invoice = Document::ofType(DocumentType::Invoice)->latest('id')->first();
        $this->patch("/invoices/{$invoice->id}/status", ['status' => 'sent']);

        return $invoice->fresh();
    }

    private function startCreditNote(Document $invoice): Document
    {
        $this->post("/invoices/{$invoice->id}/credit-note")->assertRedirect();

        return Document::ofType(DocumentType::CreditNote)->latest('id')->first();
    }

    /**
     * Save a credit note with just the fitting line at the given quantity: £60 each including VAT.
     */
    private function creditFitting(Document $creditNote, string $quantity = '1'): void
    {
        $this->put("/creditnotes/{$creditNote->id}", [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-18',
            'notes' => 'One fitting day cancelled',
            'items' => [['description' => 'Fitting', 'quantity' => $quantity, 'unit_price' => '50.00', 'tax_rate' => '20.00']],
        ])->assertSessionHasNoErrors();
    }

    private function issue(Document $creditNote): void
    {
        $this->patch("/creditnotes/{$creditNote->id}/status", ['status' => 'sent'])->assertSessionHasNoErrors();
    }

    public function test_a_credit_note_starts_as_a_draft_copy_of_the_invoice()
    {
        $invoice = $this->createInvoice();

        $response = $this->post("/invoices/{$invoice->id}/credit-note");

        $creditNote = Document::ofType(DocumentType::CreditNote)->first();
        $response->assertRedirect("/creditnotes/{$creditNote->id}/edit");

        $this->assertSame('CN-0001', $creditNote->number);
        $this->assertSame(DocumentStatus::Draft, $creditNote->status);
        $this->assertSame($invoice->id, $creditNote->source_document_id);
        $this->assertSame($this->client->id, $creditNote->client_id);
        $this->assertSame('PO-77', $creditNote->po_number);
        $this->assertSame(60000, $creditNote->total);
        $this->assertCount(2, $creditNote->items);
        $this->assertNull($creditNote->due_date);

        // A draft doesn't touch the invoice.
        $this->assertSame(60000, $invoice->fresh()->balanceDue());

        foreach (['/creditnotes', "/creditnotes/{$creditNote->id}", "/creditnotes/{$creditNote->id}/edit"] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get("/creditnotes/{$creditNote->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->where('document.credits.number', 'INV-0001')
            ->where('type.has_due_date', false));
    }

    public function test_credit_notes_can_only_be_made_from_a_sent_or_paid_invoice()
    {
        $this->get('/creditnotes/create')->assertRedirect('/invoices');
        $this->post('/creditnotes', [])->assertMethodNotAllowed();

        $this->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-18',
            'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '20.00']],
        ]);
        $draft = Document::ofType(DocumentType::Invoice)->first();

        $this->post("/invoices/{$draft->id}/credit-note")->assertForbidden();
        $this->get("/invoices/{$draft->id}")->assertInertia(fn (Assert $page) => $page->where('document.can_credit', false));

        $this->patch("/invoices/{$draft->id}/status", ['status' => 'sent']);
        $this->get("/invoices/{$draft->id}")->assertInertia(fn (Assert $page) => $page->where('document.can_credit', true));
    }

    public function test_issuing_a_part_credit_comes_off_the_invoice_balance()
    {
        $invoice = $this->createInvoice();
        $creditNote = $this->startCreditNote($invoice);
        $this->creditFitting($creditNote);

        $this->assertSame(6000, $creditNote->fresh()->total);
        // Its client can't be changed away from the invoice's.
        $this->assertSame($this->client->id, $creditNote->fresh()->client_id);

        $this->issue($creditNote);

        $invoice->refresh();
        $this->assertSame(6000, $invoice->amount_credited);
        $this->assertSame(54000, $invoice->balanceDue());
        $this->assertSame(DocumentStatus::Sent, $invoice->status);
        $this->assertFalse($invoice->isEditable());
        $this->assertFalse($creditNote->fresh()->isEditable());

        // Payments can only cover what's left.
        $this->post("/invoices/{$invoice->id}/payments", ['amount' => '600.00', 'paid_on' => '2026-11-18', 'method' => 'cash'])
            ->assertSessionHasErrors('amount');
        $this->post("/invoices/{$invoice->id}/payments", ['amount' => '540.00', 'paid_on' => '2026-11-18', 'method' => 'cash'])
            ->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Paid, $invoice->fresh()->status);

        $this->get("/invoices/{$invoice->id}")->assertInertia(fn (Assert $page) => $page
            ->where('document.amount_credited', 6000)
            ->where('document.credit_notes.0.number', 'CN-0001')
            ->where('document.credit_notes.0.total', 6000));
    }

    public function test_crediting_the_whole_invoice_marks_it_credited()
    {
        $invoice = $this->createInvoice();
        $creditNote = $this->startCreditNote($invoice);
        $this->issue($creditNote);

        $invoice->refresh();
        $this->assertSame(DocumentStatus::Credited, $invoice->status);
        $this->assertSame(0, $invoice->balanceDue());
        $this->post("/invoices/{$invoice->id}/credit-note")->assertForbidden();
        $this->patch("/invoices/{$invoice->id}/status", ['status' => 'void'])->assertForbidden();

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('totals.outstanding', 0));

        // Voiding the credit note puts the invoice back.
        $this->patch("/creditnotes/{$creditNote->id}/status", ['status' => 'void'])->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame(DocumentStatus::Sent, $invoice->status);
        $this->assertSame(60000, $invoice->balanceDue());
    }

    public function test_credit_notes_cannot_credit_more_than_is_left_of_the_invoice()
    {
        $invoice = $this->createInvoice();

        $first = $this->startCreditNote($invoice);
        $this->put("/creditnotes/{$first->id}", [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-18',
            'items' => [['description' => 'Kitchen units', 'quantity' => '1', 'unit_price' => '400.00', 'tax_rate' => '20.00']],
        ])->assertSessionHasNoErrors();
        $this->issue($first);

        // £120 is left; three fitting days would be £180.
        $second = $this->startCreditNote($invoice);
        $this->put("/creditnotes/{$second->id}", [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-18',
            'items' => [['description' => 'Fitting', 'quantity' => '3', 'unit_price' => '50.00', 'tax_rate' => '20.00']],
        ])->assertSessionHasErrors(['items' => 'That is more than the £120.00 left to credit on invoice INV-0001.']);

        // A draft that fitted when it was made is checked again when it's issued.
        $third = $this->startCreditNote($invoice);

        $this->creditFitting($second, '2');
        $this->issue($second);
        $this->assertSame(DocumentStatus::Credited, $invoice->fresh()->status);

        $this->patch("/creditnotes/{$third->id}/status", ['status' => 'sent'])->assertSessionHasErrors('status');
        $this->assertSame(DocumentStatus::Draft, $third->fresh()->status);
    }

    public function test_sending_a_draft_credit_note_issues_it()
    {
        Mail::fake();
        $invoice = $this->createInvoice();
        $creditNote = $this->startCreditNote($invoice);
        $this->creditFitting($creditNote);

        $this->get("/creditnotes/{$creditNote->id}")->assertInertia(fn (Assert $page) => $page
            ->where('document.source.number', 'INV-0001')
            ->where('document.email_message', fn (string $message) => str_contains($message, 'credit note CN-0001 for £60.00')
                && str_contains($message, 'It credits invoice INV-0001.')
                && ! str_contains($message, 'Payment is due')));

        $this->post("/creditnotes/{$creditNote->id}/send", ['email' => 'client@example.com', 'message' => 'Credit attached'])
            ->assertSessionHasNoErrors();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail) => $mail->envelope()->subject === "Credit note CN-0001 from {$this->user->currentBusiness->name} (PO PO-77)");
        $this->assertSame(DocumentStatus::Sent, $creditNote->fresh()->status);
        $this->assertSame(6000, $invoice->fresh()->amount_credited);
    }

    public function test_refunds_can_be_recorded_when_the_invoice_was_already_paid()
    {
        $invoice = $this->createInvoice();
        $this->post("/invoices/{$invoice->id}/payments", ['amount' => '600.00', 'paid_on' => '2026-11-12', 'method' => 'bank_transfer']);

        $creditNote = $this->startCreditNote($invoice);
        $this->creditFitting($creditNote);

        // Nothing is owed back until it's issued.
        $this->post("/creditnotes/{$creditNote->id}/refund", ['amount' => '60.00', 'refunded_on' => '2026-11-18', 'method' => 'bank_transfer'])
            ->assertForbidden();

        $this->issue($creditNote);
        $this->assertSame(DocumentStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(6000, $creditNote->fresh()->refundable());

        $this->post("/creditnotes/{$creditNote->id}/refund", ['amount' => '60.01', 'refunded_on' => '2026-11-18', 'method' => 'bank_transfer'])
            ->assertSessionHasErrors('amount');
        $this->post("/creditnotes/{$creditNote->id}/refund", [
            'amount' => '60.00', 'refunded_on' => '2026-11-18', 'method' => 'bank_transfer', 'reference' => 'Refund 1',
        ])->assertSessionHasNoErrors();

        $creditNote->refresh();
        $this->assertSame(6000, $creditNote->refund_amount);
        $this->assertSame('2026-11-18', $creditNote->refunded_on->toDateString());
        $this->assertSame(0, $creditNote->refundable());

        // A refunded credit note can't be voided until the refund is removed.
        $this->patch("/creditnotes/{$creditNote->id}/status", ['status' => 'void'])->assertForbidden();
        $this->delete("/creditnotes/{$creditNote->id}/refund")->assertRedirect();
        $this->assertSame(0, $creditNote->fresh()->refund_amount);
    }

    public function test_credit_notes_come_off_turnover_and_vat()
    {
        $business = $this->user->currentBusiness;
        $business->forceFill(['plan_override' => 'pro', 'vat_number' => 'GB123456789'])->save();

        $invoice = $this->createInvoice();
        $this->post("/invoices/{$invoice->id}/payments", ['amount' => '600.00', 'paid_on' => '2026-11-12', 'method' => 'bank_transfer']);
        $creditNote = $this->startCreditNote($invoice);
        $this->creditFitting($creditNote);
        $this->issue($creditNote);

        // Accruals: the credit note's £50 + £10 VAT comes off when it's dated.
        $this->get('/tax-return?year=2026&basis=accruals')->assertInertia(fn (Assert $page) => $page
            ->where('report.turnover', 45000)
            ->where('report.vat.output', 9000)
            ->where('report.credits.count', 1)
            ->where('report.credits.amount', 5000));

        // Cash: nothing changes until the money goes back.
        $this->get('/tax-return?year=2026&basis=cash')->assertInertia(fn (Assert $page) => $page
            ->where('report.turnover', 50000)
            ->where('report.vat.output', 10000));

        $this->post("/creditnotes/{$creditNote->id}/refund", ['amount' => '60.00', 'refunded_on' => '2026-11-18', 'method' => 'bank_transfer']);

        $this->get('/tax-return?year=2026&basis=cash')->assertInertia(fn (Assert $page) => $page
            ->where('report.turnover', 45000)
            ->where('report.vat.output', 9000)
            ->where('report.credits.amount', 5000));

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('totals.paid', 54000));
    }

    public function test_credit_notes_have_no_plan_limit()
    {
        $this->user->currentBusiness->forceFill(['plan_override' => 'free'])->save();
        $invoice = $this->createInvoice();

        for ($i = 0; $i < 6; $i++) {
            $this->startCreditNote($invoice);
        }

        $this->assertSame(6, Document::ofType(DocumentType::CreditNote)->count());
    }

    public function test_every_layout_names_the_credited_invoice()
    {
        $invoice = $this->createInvoice();
        $creditNote = $this->startCreditNote($invoice);
        $this->creditFitting($creditNote);

        foreach (DocumentLayout::cases() as $layout) {
            $html = DocumentPdf::html($creditNote->fresh(), new DocumentDesign(layout: $layout));

            $this->assertMatchesRegularExpression('/Credit note|CREDIT NOTE/', $html, $layout->value);
            $this->assertStringContainsString('Credits invoice', $html, $layout->value);
            $this->assertStringContainsString('INV-0001', $html, $layout->value);
            $this->assertStringContainsString('10 Nov 2026', $html, $layout->value);
            $this->assertStringContainsString('Total credit', $html, $layout->value);
            $this->assertStringNotContainsString('Due date', $html, $layout->value);
        }

        $response = $this->get("/creditnotes/{$creditNote->id}/pdf");
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('Credit note CN-0001.pdf', $response->headers->get('Content-Disposition'));
    }
}
