<?php

namespace Tests\Feature\Settings;

use App\Enums\BusinessRole;
use App\Enums\DocumentLayout;
use App\Enums\DocumentType;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Support\DocumentDesign;
use App\Support\DocumentPdf;
use App\Support\SampleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentDesignTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'document_layout' => 'modern',
            'document_colour' => '#0f766e',
            'document_accent_colour' => '#B45309',
            'document_font' => 'serif',
            'document_footer' => 'Thank you for your business!',
            ...$overrides,
        ];
    }

    public function test_the_design_page_is_displayed()
    {
        $user = User::factory()->withBusiness()->create();

        $this->actingAs($user)
            ->get('/settings/invoice-design')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/invoice-design')
                ->where('design.document_layout', 'classic')
                ->where('logoUrl', null)
                ->has('layouts', 5)
                ->where('canUpdate', true));
    }

    public function test_owners_can_save_a_design_with_a_logo()
    {
        Storage::fake(DocumentDesign::DISK);
        $user = User::factory()->withBusiness()->create();

        $this->actingAs($user)
            ->post('/settings/invoice-design', $this->payload(['logo' => UploadedFile::fake()->image('logo.png', 400, 100)]))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/invoice-design');

        $business = $user->currentBusiness->fresh();
        $this->assertSame('modern', $business->document_layout);
        $this->assertSame('#0F766E', $business->document_colour);
        $this->assertSame('serif', $business->document_font);
        $this->assertSame('Thank you for your business!', $business->document_footer);
        Storage::disk(DocumentDesign::DISK)->assertExists($business->document_logo_path);

        $this->get('/settings/invoice-design/logo')->assertOk();
    }

    public function test_replacing_or_removing_the_logo_deletes_the_old_file()
    {
        Storage::fake(DocumentDesign::DISK);
        $user = User::factory()->withBusiness()->create();
        $this->actingAs($user);

        $this->post('/settings/invoice-design', $this->payload(['logo' => UploadedFile::fake()->image('one.png')]));
        $first = $user->currentBusiness->fresh()->document_logo_path;

        $this->post('/settings/invoice-design', $this->payload(['logo' => UploadedFile::fake()->image('two.png')]));
        $second = $user->currentBusiness->fresh()->document_logo_path;
        Storage::disk(DocumentDesign::DISK)->assertMissing($first);
        Storage::disk(DocumentDesign::DISK)->assertExists($second);

        $this->post('/settings/invoice-design', $this->payload(['remove_logo' => true]));
        $this->assertNull($user->currentBusiness->fresh()->document_logo_path);
        Storage::disk(DocumentDesign::DISK)->assertMissing($second);
    }

    public function test_the_design_is_validated()
    {
        $user = User::factory()->withBusiness()->create();

        $this->actingAs($user)
            ->post('/settings/invoice-design', $this->payload([
                'document_layout' => 'fancy',
                'document_colour' => 'red',
                'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            ]))
            ->assertSessionHasErrors(['document_layout', 'document_colour', 'logo']);
    }

    public function test_members_cannot_change_the_design()
    {
        $member = User::factory()->withBusiness(BusinessRole::Member)->create();

        $this->actingAs($member)->post('/settings/invoice-design', $this->payload())->assertForbidden();
        $this->assertSame('classic', $member->currentBusiness->fresh()->document_layout);
    }

    public function test_the_preview_renders_unsaved_changes()
    {
        $user = User::factory()->withBusiness()->create();

        $response = $this->actingAs($user)
            ->post('/settings/invoice-design/preview', $this->payload(['type' => 'estimate']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $response->assertSee('ESTIMATE')->assertSee('#0F766E')->assertSee('Thank you for your business!');
        $this->assertSame('classic', $user->currentBusiness->fresh()->document_layout);
    }

    public function test_the_preview_includes_a_newly_chosen_logo()
    {
        $user = User::factory()->withBusiness()->create();

        $this->actingAs($user)
            ->post('/settings/invoice-design/preview', $this->payload(['logo' => UploadedFile::fake()->image('logo.png', 300, 100)]))
            ->assertOk()
            ->assertSee('src="data:image/png;base64,', false)
            ->assertSee('width="168" height="56"', false);
    }

    public function test_the_preview_can_be_a_pdf()
    {
        $user = User::factory()->withBusiness()->create();

        $response = $this->actingAs($user)
            ->post('/settings/invoice-design/preview', $this->payload(['format' => 'pdf']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_every_layout_renders_a_real_invoice_as_a_pdf()
    {
        Storage::fake(DocumentDesign::DISK);
        $user = User::factory()->withBusiness()->create();
        $this->actingAs($user);
        $business = $user->currentBusiness;
        $business->forceFill(['document_logo_path' => UploadedFile::fake()->image('logo.png', 200, 80)->store('logos', DocumentDesign::DISK)])->save();

        $this->post('/invoices', [
            'client_id' => Client::factory()->create()->id,
            'issue_date' => '2026-11-17',
            'due_date' => '2026-12-17',
            'items' => [
                ['description' => 'Design', 'quantity' => '2.5', 'unit_price' => '100.00', 'tax_rate' => '20.00'],
                ['description' => 'Book', 'quantity' => '3', 'unit_price' => '9.99', 'tax_rate' => '0.00'],
            ],
        ])->assertSessionHasNoErrors();
        $invoice = Document::latest('id')->first();

        foreach (DocumentLayout::cases() as $layout) {
            $business->update(['document_layout' => $layout->value]);
            $invoice->unsetRelation('business');

            $this->assertStringContainsString('data:image/png;base64,', DocumentPdf::html($invoice));
            $this->assertStringStartsWith('%PDF', DocumentPdf::render($invoice), $layout->value);
        }
    }

    public function test_the_footer_shows_contact_and_bank_details()
    {
        $user = User::factory()->withBusiness()->create();
        $this->actingAs($user);

        $this->patch('/settings/business', [
            'name' => 'Tandonta Web Development',
            'email' => 'info@example.com',
            'phone' => '01902 000000',
            'website' => 'www.example.com',
            'contact_name' => 'Stuart Corns',
            'address_line_1' => '1 Hatton House',
            'city' => 'Wolverhampton',
            'postcode' => 'WV10 0SZ',
            'company_number' => '01234567',
            'bank_name' => 'Starling',
            'bank_account_name' => 'Tandonta Web Design',
            'bank_sort_code' => '608371',
            'bank_account_number' => '12345678',
            'bank_iban' => 'gb03 srlg 6083 7112 3456 78',
            'bank_bic' => 'srlggb2l',
        ])->assertSessionHasNoErrors();

        $business = $user->currentBusiness->fresh();
        $this->assertSame('60-83-71', $business->bank_sort_code);
        $this->assertSame('GB03SRLG60837112345678', $business->bank_iban);
        $this->assertSame('SRLGGB2L', $business->bank_bic);

        $this->post('/settings/invoice-design/preview', $this->payload())
            ->assertOk()
            ->assertSee('<strong>Tandonta Web Development</strong>, 1 Hatton House, Wolverhampton, WV10 0SZ', false)
            ->assertSee('Contact person: <strong>Stuart Corns</strong>', false)
            ->assertSee('Web: <strong>www.example.com</strong>', false)
            ->assertSee('Sort code: <strong>60-83-71</strong>', false)
            ->assertSee('BIC/SWIFT: <strong>SRLGGB2L</strong>', false)
            ->assertSee('Company number: <strong>01234567</strong>', false);

        // Turning the details off keeps only the name and the legal details.
        $this->post('/settings/invoice-design/preview', $this->payload(['document_footer_details' => false]))
            ->assertOk()
            ->assertDontSee('Sort code:', false)
            ->assertSee('Company number: <strong>01234567</strong>', false);
    }

    public function test_bank_details_are_validated()
    {
        $user = User::factory()->withBusiness()->create();

        $this->actingAs($user)
            ->patch('/settings/business', ['name' => 'Acme', 'bank_sort_code' => '12-34', 'bank_bic' => 'nope'])
            ->assertSessionHasErrors(['bank_sort_code', 'bank_bic']);
    }

    public function test_a_signature_can_be_added_to_documents()
    {
        Storage::fake(DocumentDesign::DISK);
        $user = User::factory()->withBusiness()->create();
        $this->actingAs($user);

        $this->post('/settings/invoice-design', $this->payload([
            'signature' => UploadedFile::fake()->image('signature.png', 400, 140),
            'document_show_signature' => true,
            'document_signature_name' => 'Stuart Corns',
        ]))->assertSessionHasNoErrors();

        $business = $user->currentBusiness->fresh();
        $this->assertTrue($business->document_show_signature);
        Storage::disk(DocumentDesign::DISK)->assertExists($business->document_signature_path);
        $this->get('/settings/invoice-design/signature')->assertOk();

        $invoice = SampleDocument::make($business, DocumentType::Invoice);
        $html = DocumentPdf::html($invoice);
        $this->assertStringContainsString('Issued by, signature', $html);
        $this->assertStringContainsString('alt="Signature"', $html);
        $this->assertStringContainsString('Stuart Corns', $html);
        $this->assertStringStartsWith('%PDF', DocumentPdf::render($invoice));

        // Switched off, the signature stays saved but isn't printed.
        $this->post('/settings/invoice-design', $this->payload(['document_show_signature' => false]));
        $invoice = SampleDocument::make($business->fresh(), DocumentType::Invoice);
        $this->assertStringNotContainsString('alt="Signature"', DocumentPdf::html($invoice));

        // Removing it deletes the file and can't leave the toggle on.
        $path = $business->document_signature_path;
        $this->post('/settings/invoice-design', $this->payload(['remove_signature' => true, 'document_show_signature' => true]));
        $business->refresh();
        $this->assertNull($business->document_signature_path);
        $this->assertFalse($business->document_show_signature);
        Storage::disk(DocumentDesign::DISK)->assertMissing($path);
    }

    public function test_the_preview_shows_a_newly_drawn_signature()
    {
        $user = User::factory()->withBusiness()->create();

        $this->actingAs($user)
            ->post('/settings/invoice-design/preview', $this->payload([
                'signature' => UploadedFile::fake()->image('signature.png', 600, 200),
                'document_show_signature' => true,
            ]))
            ->assertOk()
            ->assertSee('alt="Signature" ', false)
            ->assertSee('width="200" height="67"', false);
    }

    public function test_text_on_colours_stays_readable()
    {
        $this->assertSame('#FFFFFF', DocumentDesign::textOn('#071B49'));
        $this->assertSame('#111827', DocumentDesign::textOn('#FDE68A'));
        $this->assertSame('#1F2937', DocumentDesign::readableOnWhite('#1F2937'));
        $this->assertNotSame('#FDE68A', DocumentDesign::readableOnWhite('#FDE68A'));
    }
}
