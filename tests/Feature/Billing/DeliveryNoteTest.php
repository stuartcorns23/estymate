<?php

namespace Tests\Feature\Billing;

use App\Enums\DocumentLayout;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Support\DocumentDesign;
use App\Support\DocumentPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeliveryNoteTest extends TestCase
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

    private function createDeliveryNote(array $overrides = []): Document
    {
        $this->post('/deliverynotes', [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-18',
            'po_number' => 'PO-9',
            'delivery_address' => "Site office\nUnit 4, Riverside Park",
            'items' => [
                ['description' => 'Oak worktop', 'quantity' => '3', 'unit' => 'lengths'],
                ['description' => 'Sink', 'quantity' => '1', 'unit_price' => '', 'tax_rate' => ''],
            ],
            ...$overrides,
        ])->assertSessionHasNoErrors();

        return Document::ofType(DocumentType::DeliveryNote)->latest('id')->first();
    }

    public function test_delivery_notes_are_made_without_prices()
    {
        $deliveryNote = $this->createDeliveryNote(['due_date' => '2026-12-01']);

        $this->assertSame('DN-0001', $deliveryNote->number);
        $this->assertSame(DocumentStatus::Draft, $deliveryNote->status);
        $this->assertSame(0, $deliveryNote->total);
        $this->assertNull($deliveryNote->due_date);
        $this->assertSame("Site office\nUnit 4, Riverside Park", $deliveryNote->delivery_address);
        $this->assertCount(2, $deliveryNote->items);
        $this->assertSame('lengths', $deliveryNote->items[0]->unit);

        foreach (['/deliverynotes', '/deliverynotes/create', "/deliverynotes/{$deliveryNote->id}", "/deliverynotes/{$deliveryNote->id}/edit"] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get("/deliverynotes/{$deliveryNote->id}")->assertInertia(fn (Assert $page) => $page
            ->where('type.shows_prices', false)
            ->where('document.delivery_address', "Site office\nUnit 4, Riverside Park")
            ->where('document.email_message', fn (string $message) => str_contains($message, 'delivery note DN-0001, for your purchase order PO-9.')
                && ! str_contains($message, '£')));
    }

    public function test_other_documents_still_need_prices_and_drop_a_delivery_address()
    {
        $this->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-18',
            'items' => [['description' => 'Work', 'quantity' => '1']],
        ])->assertSessionHasErrors(['items.0.unit_price', 'items.0.tax_rate']);

        $this->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-18',
            'delivery_address' => 'Somewhere',
            'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '20.00']],
        ])->assertSessionHasNoErrors();

        $this->assertNull(Document::ofType(DocumentType::Invoice)->first()->delivery_address);
    }

    public function test_a_delivery_note_can_be_made_from_an_invoice_estimate_or_pro_forma()
    {
        foreach (['invoices', 'estimates', 'proformas'] as $plural) {
            $this->post("/{$plural}", [
                'client_id' => $this->client->id,
                'issue_date' => '2026-11-10',
                'po_number' => 'PO-1',
                'items' => [['description' => 'Boiler', 'quantity' => '2', 'unit_price' => '800.00', 'tax_rate' => '20.00']],
            ])->assertSessionHasNoErrors();

            $source = Document::latest('id')->first();
            $response = $this->post("/{$plural}/{$source->id}/delivery-note");

            $deliveryNote = Document::ofType(DocumentType::DeliveryNote)->latest('id')->first();
            $response->assertRedirect("/deliverynotes/{$deliveryNote->id}/edit");

            $this->assertSame($source->id, $deliveryNote->source_document_id);
            $this->assertSame('PO-1', $deliveryNote->po_number);
            $this->assertSame('Boiler', $deliveryNote->items[0]->description);
            $this->assertSame('2026-11-18', $deliveryNote->issue_date->toDateString());

            $this->get("/{$plural}/{$source->id}")->assertInertia(fn (Assert $page) => $page
                ->where('document.delivery_notes.0.number', $deliveryNote->number)
                // An estimate's or pro-forma's delivery note isn't the invoice it was converted into.
                ->where('document.converted_to', null));
        }

        $this->assertSame(['DN-0001', 'DN-0002', 'DN-0003'], Document::ofType(DocumentType::DeliveryNote)->orderBy('id')->pluck('number')->all());

        $deliveryNote = Document::ofType(DocumentType::DeliveryNote)->first();
        $this->post("/deliverynotes/{$deliveryNote->id}/delivery-note")->assertNotFound();
    }

    public function test_delivery_notes_can_be_marked_delivered()
    {
        $deliveryNote = $this->createDeliveryNote();

        $this->patch("/deliverynotes/{$deliveryNote->id}/status", ['status' => 'delivered'])->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Delivered, $deliveryNote->fresh()->status);

        $this->patch("/deliverynotes/{$deliveryNote->id}/status", ['status' => 'paid'])->assertSessionHasErrors('status');
        $this->post("/deliverynotes/{$deliveryNote->id}/payments", ['amount' => '1.00'])->assertNotFound();
    }

    public function test_every_layout_leaves_prices_off_and_has_room_to_sign()
    {
        $this->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-10',
            'items' => [['description' => 'Boiler', 'quantity' => '2', 'unit_price' => '812.34', 'tax_rate' => '20.00']],
        ]);
        $this->post('/invoices/'.Document::ofType(DocumentType::Invoice)->first()->id.'/delivery-note');
        $deliveryNote = Document::ofType(DocumentType::DeliveryNote)->first();
        $deliveryNote->update(['delivery_address' => 'Back gate, 12 Mill Lane']);

        foreach (DocumentLayout::cases() as $layout) {
            $html = DocumentPdf::html($deliveryNote->fresh(), new DocumentDesign(layout: $layout));

            $this->assertMatchesRegularExpression('/Delivery note|DELIVERY NOTE/', $html, $layout->value);
            $this->assertStringContainsString('Boiler', $html, $layout->value);
            $this->assertStringContainsString('Deliver to', $html, $layout->value);
            $this->assertStringContainsString('Back gate, 12 Mill Lane', $html, $layout->value);
            $this->assertStringContainsString('Signature', $html, $layout->value);
            $this->assertStringNotContainsString('812.34', $html, $layout->value);
            $this->assertStringNotContainsString('Unit price', $html, $layout->value);
            $this->assertStringNotContainsString('Subtotal', $html, $layout->value);
            $this->assertStringNotContainsString('Tax point', $html, $layout->value);
        }

        // The client's page never receives the prices either.
        $this->get('/d/'.$deliveryNote->public_token)->assertInertia(fn (Assert $page) => $page
            ->where('document.shows_prices', false)
            ->where('document.total', 0)
            ->missing('document.items.0.unit_price'));
    }

    public function test_delivery_notes_stay_out_of_money_figures_and_have_a_plan_limit()
    {
        $this->user->currentBusiness->forceFill(['plan_override' => 'free'])->save();

        for ($i = 0; $i < 5; $i++) {
            $this->createDeliveryNote();
        }

        Document::ofType(DocumentType::DeliveryNote)->update(['status' => DocumentStatus::Sent, 'total' => 1000]);

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('totals.outstanding', 0)
            ->where('usage.deliverynotes_this_month', 5));

        $this->get('/deliverynotes/create')->assertRedirect(route('billing.edit'));

        // Making one from an invoice counts too, but invoices keep their own allowance.
        $this->get('/invoices/create')->assertOk();
        $this->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => '2026-11-18',
            'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '20.00']],
        ])->assertSessionHasNoErrors();
        $this->post('/invoices/'.Document::ofType(DocumentType::Invoice)->first()->id.'/delivery-note')
            ->assertRedirect(route('billing.edit'));
        $this->assertSame(5, Document::ofType(DocumentType::DeliveryNote)->count());
    }
}
