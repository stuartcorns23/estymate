<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\User;
use App\Support\ClientImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A made-up export in Billdu's format, with the kinds of mess real exports have.
     */
    private function billduCsv(): string
    {
        return "\xEF\xBB\xBF".implode("\r\n", [
            '"Business name","Street","City","Zip Code","Country code","ID","TAX ID","VAT Reg. No.","Contact person","E-mails","Phone","Cell","Fax","Website","Note"',
            '"Acme Plumbing","1 High Street","Wolverhampton","wv1 1aa","GB","","","GB123456789","Ann Smith","info@acme.test","01902 123456","","","www.acme.test",""',
            '"Bright Signs Ltd","2 Low Road,","Walsall","WS1 2BB","GB","12345678","","","Bob Jones","","","07700 900123","","","Pays late"',
            '"Corner Shop","","","","GB","","","","Cat Lee","shop@corner.test","01902","07700 900456","","",""',
            '"Corner  Shop Ltd","9 Other Road","Dudley","DY1 1CC","GB","","","","Dan Lee","other@corner.test","","","","",""',
            '"","3 Nowhere Lane","Dudley","","GB","","","","Nobody","nobody@example.test","","","","",""',
            '"Two Emails Co","","","","GB","","","","","first@two.test; second@two.test","","","","",""',
            '"Bad Email Ltd","","","","GB","","","","","not-an-email","","","","",""',
        ]);
    }

    private function user(string $plan = 'pro'): User
    {
        $user = User::factory()->withBusiness()->create();
        $user->currentBusiness->forceFill(['plan_override' => $plan])->save();
        $this->actingAs($user);

        return $user;
    }

    public function test_import_page_is_displayed()
    {
        $this->user();

        $this->get('/clients/import')->assertOk();
    }

    public function test_billdu_exports_are_recognised_and_mapped()
    {
        $this->user();

        $response = $this->postJson('/clients/import/preview', ['csv' => $this->billduCsv()])->assertOk();

        $response->assertJsonPath('format', 'Billdu');
        $response->assertJsonPath('mapping', [
            'name', 'address_line_1', 'city', 'postcode', 'country', 'notes', 'notes', 'vat_number',
            'contact_name', 'email', 'phone', 'phone', 'notes', 'notes', 'notes',
        ]);
        $response->assertJsonPath('headers.0', 'Business name');
    }

    public function test_preview_tidies_rows_and_flags_problems()
    {
        $this->user();
        $rows = collect($this->postJson('/clients/import/preview', ['csv' => $this->billduCsv()])->json('rows'))->keyBy('row');

        // A clean row, with the postcode tidied and the website kept in the notes.
        $this->assertSame('ready', $rows[2]['status']);
        $this->assertSame('WV1 1AA', $rows[2]['attributes']['postcode']);
        $this->assertSame('GB123456789', $rows[2]['attributes']['vat_number']);
        $this->assertSame('Website: www.acme.test', $rows[2]['attributes']['notes']);

        // The mobile fills in for a missing phone; the stray comma and company number are handled.
        $this->assertSame('ready', $rows[3]['status']);
        $this->assertSame('07700 900123', $rows[3]['attributes']['phone']);
        $this->assertSame('2 Low Road', $rows[3]['attributes']['address_line_1']);
        $this->assertSame("ID: 12345678\nPays late", $rows[3]['attributes']['notes']);

        // A phone of just an area code is kept in the notes and the mobile used instead.
        $this->assertSame('warning', $rows[4]['status']);
        $this->assertSame('07700 900456', $rows[4]['attributes']['phone']);
        $this->assertStringContainsString('Phone: 01902', $rows[4]['attributes']['notes']);

        // "Corner  Shop Ltd" is the same business as "Corner Shop" above.
        $this->assertSame('duplicate', $rows[5]['status']);
        $this->assertStringContainsString('row 4', $rows[5]['messages'][0]);

        $this->assertSame('invalid', $rows[6]['status']);

        $this->assertSame('first@two.test', $rows[7]['attributes']['email']);
        $this->assertStringContainsString('second@two.test', $rows[7]['attributes']['notes']);

        $this->assertSame('warning', $rows[8]['status']);
        $this->assertArrayNotHasKey('email', $rows[8]['attributes']);
        $this->assertStringContainsString('not-an-email', $rows[8]['attributes']['notes']);
    }

    public function test_existing_clients_are_flagged_as_duplicates()
    {
        $this->user();
        Client::factory()->create(['name' => 'ACME PLUMBING LTD']);
        Client::factory()->create(['name' => 'Someone Else', 'email' => 'first@two.test']);

        $rows = collect($this->postJson('/clients/import/preview', ['csv' => $this->billduCsv()])->json('rows'))->keyBy('row');

        $this->assertSame('duplicate', $rows[2]['status']);
        $this->assertStringContainsString('already in your clients', $rows[2]['messages'][0]);
        $this->assertSame('duplicate', $rows[7]['status']);
    }

    public function test_chosen_rows_are_imported()
    {
        $user = $this->user();

        $this->post('/clients/import', ['csv' => $this->billduCsv(), 'rows' => [2]])->assertSessionHasErrors('mapping');

        $mapping = $this->postJson('/clients/import/preview', ['csv' => $this->billduCsv()])->json('mapping');

        $this->post('/clients/import', ['csv' => $this->billduCsv(), 'mapping' => $mapping, 'rows' => [2, 3, 6]])
            ->assertRedirect('/clients')
            ->assertSessionHas('status', 'Imported 2 clients.');

        // Row 6 has no name, so it's skipped even though it was ticked.
        $this->assertSame(['Acme Plumbing', 'Bright Signs Ltd'], Client::orderBy('name')->pluck('name')->all());
        $this->assertDatabaseHas('clients', [
            'business_id' => $user->current_business_id,
            'name' => 'Acme Plumbing',
            'email' => 'info@acme.test',
            'country' => 'GB',
            'postcode' => 'WV1 1AA',
        ]);
    }

    public function test_imports_stop_at_the_plan_limit()
    {
        $this->user('free');
        Client::factory()->count(3)->create();
        $csv = $this->billduCsv();

        $this->postJson('/clients/import/preview', ['csv' => $csv])
            ->assertJsonPath('plan.limit', 5)
            ->assertJsonPath('plan.remaining', 2);

        $mapping = ClientImport::fromCsv($csv)->guessMapping();

        $this->post('/clients/import', ['csv' => $csv, 'mapping' => $mapping, 'rows' => [2, 3, 4, 7]])
            ->assertRedirect('/clients')
            ->assertSessionHas('status', 'Imported 2 clients. 2 more were left out because the Free plan includes 5 clients. Upgrade to import the rest.');

        $this->assertSame(5, Client::count());
        $this->assertDatabaseHas('clients', ['name' => 'Bright Signs Ltd']);
        $this->assertDatabaseMissing('clients', ['name' => 'Corner Shop']);
    }

    public function test_other_csvs_can_be_mapped_by_hand()
    {
        $this->user();
        $csv = "Company;Who;Mail;Town\nNorth Ltd;Eve;eve@north.test;Leeds\n";

        $preview = $this->postJson('/clients/import/preview', ['csv' => $csv])->assertOk();
        $preview->assertJsonPath('format', null);
        $preview->assertJsonPath('mapping', ['name', null, 'email', 'city']);

        $this->post('/clients/import', ['csv' => $csv, 'mapping' => ['name', 'contact_name', 'email', 'county'], 'rows' => [2]])
            ->assertRedirect('/clients');

        $this->assertDatabaseHas('clients', ['name' => 'North Ltd', 'contact_name' => 'Eve', 'county' => 'Leeds', 'city' => null]);
    }

    public function test_unreadable_files_are_rejected()
    {
        $this->user();

        $this->postJson('/clients/import/preview', ['csv' => "Name,Email\n"])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('csv');

        $this->postJson('/clients/import/preview', ['csv' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('csv');

        $this->postJson('/clients/import/preview', ['csv' => "Name\nA", 'mapping' => ['password']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mapping.0');
    }

    public function test_windows_1252_text_is_read()
    {
        // The browser decodes files before sending them, but the parser copes either way.
        $import = ClientImport::fromCsv("Name,Notes\nCaf\xE9 Bleu,\xA3100 deposit\n");

        $this->assertSame([['Café Bleu', '£100 deposit']], $import->rows);
    }
}
