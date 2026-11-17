<?php

namespace Tests\Feature\Billing;

use App\Models\Expense;
use App\Models\User;
use App\Services\ExpenseReader\ClaudeExpenseReader;
use App\Services\ExpenseReader\ExpenseReader;
use App\Services\ExpenseReader\ExpenseReaderFailed;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpenseScanTest extends TestCase
{
    use RefreshDatabase;

    private function fakeReader(array|ExpenseReaderFailed $result, bool $available = true): void
    {
        $this->app->instance(ExpenseReader::class, new class($result, $available) implements ExpenseReader
        {
            public ?string $mimeType = null;

            public function __construct(private array|ExpenseReaderFailed $result, private bool $isAvailable) {}

            public function available(): bool
            {
                return $this->isAvailable;
            }

            public function read(string $contents, string $mimeType): array
            {
                $this->mimeType = $mimeType;

                if ($this->result instanceof ExpenseReaderFailed) {
                    throw $this->result;
                }

                return $this->result;
            }
        });
    }

    public function test_dropped_documents_are_read_into_suggested_fields()
    {
        $this->fakeReader(['supplier' => 'Screwfix', 'total' => '120.00', 'vat_amount' => '20.00']);

        $this->actingAs(User::factory()->withBusiness()->create())
            ->postJson('/expenses/scan', ['attachment' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf')])
            ->assertOk()
            ->assertExactJson(['fields' => ['supplier' => 'Screwfix', 'total' => '120.00', 'vat_amount' => '20.00']]);

        $this->assertSame('application/pdf', app(ExpenseReader::class)->mimeType);
        $this->assertSame(0, Expense::count());
    }

    public function test_reading_failures_are_shown_to_the_person()
    {
        $this->fakeReader(new ExpenseReaderFailed('The document couldn\'t be read.'));

        $this->actingAs(User::factory()->withBusiness()->create())
            ->postJson('/expenses/scan', ['attachment' => UploadedFile::fake()->image('receipt.jpg')])
            ->assertStatus(422)
            ->assertJson(['message' => 'The document couldn\'t be read.']);
    }

    public function test_unreadable_file_types_are_rejected()
    {
        $this->fakeReader([]);

        $this->actingAs(User::factory()->withBusiness()->create())
            ->postJson('/expenses/scan', ['attachment' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])
            ->assertJsonValidationErrors('attachment');
    }

    public function test_reading_is_off_without_an_api_key()
    {
        config(['services.anthropic.key' => null]);
        $user = User::factory()->withBusiness()->create();

        $this->actingAs($user)
            ->postJson('/expenses/scan', ['attachment' => UploadedFile::fake()->image('receipt.jpg')])
            ->assertNotFound();

        $this->get('/expenses/create')->assertInertia(fn (Assert $page) => $page->where('canScan', false));
    }

    public function test_guests_cannot_read_documents()
    {
        $this->postJson('/expenses/scan')->assertUnauthorized();
    }

    public function test_attachments_are_written_by_contents()
    {
        Storage::fake(Expense::DISK);
        $user = User::factory()->withBusiness()->create();

        $this->actingAs($user)->post('/expenses', [
            'supplier' => 'Screwfix',
            'date' => '2026-11-10',
            'category' => 'materials',
            'total' => '12',
            'attachment' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $path = Expense::first()->attachment_path;
        $this->assertStringStartsWith("expenses/{$user->current_business_id}/", $path);
        $this->assertStringEndsWith('.pdf', $path);
        Storage::disk(Expense::DISK)->assertExists($path);
    }

    /**
     * Run the real Claude reader against a canned API response.
     *
     * @param  list<array<string, mixed>>  $history
     */
    private function claudeReader(array $body, array &$history = []): ClaudeExpenseReader
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode($body))]));
        $stack->push(Middleware::history($history));

        return new ClaudeExpenseReader('test-key', 'claude-opus-5-5', ['transporter' => new GuzzleClient(['handler' => $stack]), 'maxRetries' => 0]);
    }

    private function message(string $text, string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ];
    }

    public function test_claude_reader_sends_the_document_and_cleans_the_answer()
    {
        $history = [];
        $reader = $this->claudeReader($this->message(json_encode([
            'supplier' => ' Screwfix Direct ',
            'reference' => 'INV-42',
            'date' => '2026-04-03',
            'total' => '£1,200.50',
            'vat_amount' => '200.08',
            'category' => 'materials',
            'description' => null,
        ])), $history);

        $fields = $reader->read('%PDF-1.4 fake', 'application/pdf');

        $this->assertSame([
            'supplier' => 'Screwfix Direct',
            'reference' => 'INV-42',
            'date' => '2026-04-03',
            'total' => '1200.50',
            'vat_amount' => '200.08',
            'category' => 'materials',
        ], $fields);

        $request = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('claude-opus-5-5', $request['model']);
        $this->assertSame('document', $request['messages'][0]['content'][0]['type']);
        $this->assertSame('application/pdf', $request['messages'][0]['content'][0]['source']['media_type']);
        $this->assertSame(base64_encode('%PDF-1.4 fake'), $request['messages'][0]['content'][0]['source']['data']);
        $this->assertSame('json_schema', $request['output_config']['format']['type']);
        $this->assertSame('default', $request['fallbacks']);
    }

    public function test_claude_reader_drops_values_it_cannot_trust()
    {
        $reader = $this->claudeReader($this->message(json_encode([
            'supplier' => '',
            'reference' => null,
            'date' => '03/04/2026',
            'total' => '10.00',
            'vat_amount' => '50.00',
            'category' => 'yachts',
            'description' => 'Fuel',
        ])));

        $this->assertSame(['total' => '10.00', 'description' => 'Fuel'], $reader->read('image', 'image/png'));
    }

    public function test_claude_reader_reports_refusals()
    {
        $this->expectException(ExpenseReaderFailed::class);

        $this->claudeReader($this->message('', 'refusal'))->read('image', 'image/jpeg');
    }

    public function test_claude_reader_rejects_unsupported_types()
    {
        $this->expectException(ExpenseReaderFailed::class);

        (new ClaudeExpenseReader('test-key', 'claude-opus-5-5'))->read('image', 'image/heic');
    }
}
