<?php

namespace Tests\Feature\Billing;

use App\Enums\DocumentLayout;
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

class PoNumberTest extends TestCase
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
        $this->client = Client::factory()->create(['email' => 'ap@bigco.test', 'default_po_number' => 'PO-4500123']);
    }

    private function create(DocumentType $type, ?string $poNumber): Document
    {
        $this->post("/{$type->plural()}", [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-18',
            'po_number' => $poNumber,
            'items' => [['description' => 'Kitchen fit', 'quantity' => '1', 'unit_price' => '500.00', 'tax_rate' => '20.00']],
        ])->assertSessionHasNoErrors();

        return Document::ofType($type)->latest('id')->first();
    }

    public function test_clients_can_have_a_default_po_number()
    {
        $this->put("/clients/{$this->client->id}", [
            'name' => $this->client->name,
            'default_po_number' => 'PO-777',
        ])->assertSessionHasNoErrors();

        $this->assertSame('PO-777', $this->client->fresh()->default_po_number);
    }

    public function test_new_documents_start_with_the_clients_po_number()
    {
        foreach (DocumentType::cases() as $type) {
            $this->get("/{$type->plural()}/create?client_id={$this->client->id}")->assertInertia(fn (Assert $page) => $page
                ->where('document.po_number', 'PO-4500123')
                ->where('clients.0.default_po_number', 'PO-4500123'));
        }
    }

    public function test_each_document_type_saves_its_own_po_number()
    {
        foreach (DocumentType::cases() as $type) {
            $document = $this->create($type, 'PO-'.$type->value);

            $this->assertSame('PO-'.$type->value, $document->po_number);
            $this->get("/{$type->plural()}/{$document->id}/edit")->assertInertia(fn (Assert $page) => $page
                ->where('document.po_number', 'PO-'.$type->value));
        }

        $this->assertNull($this->create(DocumentType::Invoice, null)->po_number);
    }

    public function test_documents_can_be_found_by_po_number()
    {
        $this->create(DocumentType::Invoice, 'PO-FINDME');
        $this->create(DocumentType::Invoice, 'PO-OTHER');

        $this->get('/invoices?search=FINDME')->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));
    }

    public function test_the_po_number_carries_over_when_converting()
    {
        $estimate = $this->create(DocumentType::Estimate, 'PO-EST');
        $this->post("/estimates/{$estimate->id}/convert");

        $proForma = $this->create(DocumentType::ProForma, 'PO-PF');
        $this->post("/proformas/{$proForma->id}/convert", [
            'paid' => true, 'amount' => '600.00', 'paid_on' => '2026-11-18', 'method' => 'bank_transfer',
        ])->assertSessionHasNoErrors();

        $this->assertSame('PO-EST', $estimate->convertedTo->po_number);
        $this->assertSame('PO-PF', $proForma->convertedTo->po_number);
    }

    public function test_the_po_number_is_on_every_pdf_layout()
    {
        foreach (DocumentType::cases() as $type) {
            $document = $this->create($type, 'PO-4500123');

            foreach (DocumentLayout::cases() as $layout) {
                $html = DocumentPdf::html($document->fresh(), new DocumentDesign(layout: $layout));

                $this->assertStringContainsString('PO number', $html, "{$type->value} {$layout->value}");
                $this->assertStringContainsString('PO-4500123', $html, "{$type->value} {$layout->value}");
            }
        }

        $html = DocumentPdf::html($this->create(DocumentType::Invoice, null)->fresh());
        $this->assertStringNotContainsString('PO number', $html);
    }

    public function test_emails_mention_the_po_number()
    {
        Mail::fake();
        $invoice = $this->create(DocumentType::Invoice, 'PO-4500123');

        $this->get("/invoices/{$invoice->id}")->assertInertia(fn (Assert $page) => $page
            ->where('document.po_number', 'PO-4500123')
            ->where('document.email_message', fn (string $message) => str_contains($message, 'for your purchase order PO-4500123')));

        $this->post("/invoices/{$invoice->id}/send", ['email' => 'ap@bigco.test', 'message' => 'Hello'])->assertSessionHasNoErrors();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail) => $mail->envelope()->subject === "Invoice {$invoice->number} from {$this->user->currentBusiness->name} (PO PO-4500123)");
    }

    public function test_the_public_page_shows_the_po_number()
    {
        $invoice = $this->create(DocumentType::Invoice, 'PO-4500123');
        auth()->logout();

        $this->get($invoice->publicUrl())->assertInertia(fn (Assert $page) => $page->where('document.po_number', 'PO-4500123'));
    }
}
