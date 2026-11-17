<?php

namespace Tests\Feature\Billing;

use App\Billing\Plan;
use App\Enums\BusinessRole;
use App\Enums\DocumentStatus;
use App\Enums\ExpenseCategory;
use App\Models\AssistantConversation;
use App\Models\AssistantInvoiceDraft;
use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\Document;
use App\Models\Expense;
use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\AssistantTools;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    /**
     * Requests sent to the fake Claude API.
     *
     * @var list<array<string, mixed>>
     */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-17 10:00:00');

        $this->user = User::factory()->withBusiness()->create();
        $this->actingAs($this->user);
        $this->client = Client::factory()->create(['name' => 'Acme Builders']);
    }

    public function test_the_page_is_shown_with_the_assistant_on_or_off()
    {
        $this->get('/assistant')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('assistant/index')
            ->where('available', false)
            ->where('features.assistant', false));

        $this->fakeClaude([]);

        $this->get('/assistant')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('available', true)
            ->where('features.assistant', true));
    }

    public function test_questions_are_answered_using_this_businesss_invoices_only()
    {
        $overdue = $this->createInvoice(['due_date' => '2026-11-01'], sent: true);
        $this->createInvoice(['due_date' => '2026-12-30'], sent: true);
        $theirs = $this->otherBusinessInvoice();

        $this->fakeClaude([
            $this->message([
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search_invoices', 'input' => ['status' => 'overdue']],
            ], 'tool_use'),
            $this->message([['type' => 'text', 'text' => "One invoice is overdue: {$overdue->number}."]]),
        ]);

        $response = $this->postJson('/assistant/messages', ['message' => 'What is overdue?'])
            ->assertOk()
            ->assertJsonPath('message.role', 'assistant')
            ->assertJsonPath('message.body', "One invoice is overdue: {$overdue->number}.");

        $second = $this->request(1);
        $toolResult = json_decode($second['messages'][2]['content'][0]['content'], true);
        $this->assertSame('toolu_1', $second['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame(1, $toolResult['count']);
        $this->assertSame($overdue->number, $toolResult['invoices'][0]['number']);
        $this->assertSame('overdue', $toolResult['invoices'][0]['status']);
        $this->assertStringNotContainsString($theirs->client->name, $second['messages'][2]['content'][0]['content']);

        // The model's reply, thinking included, is sent back exactly as it came.
        $this->assertSame(['type' => 'thinking', 'signature' => 'sig-1', 'thinking' => ''], $second['messages'][1]['content'][0]);

        $first = $this->request(0);
        $this->assertSame('claude-opus-5-5', $first['model']);
        $this->assertStringContainsString('Today: Tuesday 17 November 2026', $first['system'][1]['text']);
        $this->assertContains('prepare_invoice', array_column($first['tools'], 'name'));

        $conversation = AssistantConversation::findOrFail($response->json('conversation_id'));
        $this->assertSame('What is overdue?', $conversation->title);
        $this->assertCount(4, $conversation->transcript);
        $this->assertSame(['user', 'assistant'], $conversation->messages->pluck('role')->all());
        $this->assertSame(20, $conversation->messages[1]->input_tokens);
    }

    public function test_tools_never_reach_another_business()
    {
        $theirs = $this->otherBusinessInvoice();
        $theirClient = $theirs->client;
        Expense::factory()->create(['supplier' => 'Our Timber', 'total' => 12000, 'vat_amount' => 2000, 'date' => '2026-10-02', 'category' => ExpenseCategory::Materials]);
        Expense::factory()->create(['business_id' => $theirs->business_id, 'supplier' => 'Their Timber', 'total' => 99900, 'date' => '2026-10-03', 'category' => ExpenseCategory::Materials]);

        $tools = $this->tools();

        $this->assertSame(0, $this->runTool($tools, 'search_invoices', ['status' => 'any'])['count']);
        $this->assertTrue($tools->run('get_invoice', ['number' => $theirs->number])['is_error']);
        $this->assertSame([], $this->runTool($tools, 'find_clients', ['search' => $theirClient->name])['clients']);

        $expenses = $this->runTool($tools, 'search_expenses', ['category' => 'materials']);
        $this->assertSame(1, $expenses['count']);
        $this->assertSame('Our Timber', $expenses['expenses'][0]['supplier']);

        $prepared = $tools->run('prepare_invoice', [
            'client_id' => $theirClient->id,
            'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => '10', 'vat_rate' => '20']],
        ]);
        $this->assertTrue($prepared['is_error']);
        $this->assertSame(0, AssistantInvoiceDraft::withoutGlobalScopes()->count());
    }

    public function test_expense_totals_are_grouped()
    {
        Expense::factory()->create(['supplier' => 'Timber Co', 'total' => 12000, 'vat_amount' => 2000, 'date' => '2026-07-02', 'category' => ExpenseCategory::Materials]);
        Expense::factory()->create(['supplier' => 'Timber Co', 'total' => 6000, 'vat_amount' => 1000, 'date' => '2026-09-30', 'category' => ExpenseCategory::Materials]);
        Expense::factory()->create(['supplier' => 'Fuel Stop', 'total' => 5000, 'vat_amount' => 0, 'date' => '2026-08-15', 'category' => ExpenseCategory::Vehicle]);
        Expense::factory()->create(['supplier' => 'Timber Co', 'total' => 99900, 'vat_amount' => 0, 'date' => '2026-10-01', 'category' => ExpenseCategory::Materials]);

        $totals = $this->runTool($this->tools(), 'expense_totals', ['from' => '2026-07-01', 'to' => '2026-09-30', 'group_by' => 'category']);

        $this->assertSame(['expenses' => 3, 'total' => '£230.00', 'vat' => '£30.00', 'net' => '£200.00'], $totals['overall']);
        $this->assertSame(['group' => 'Stock & materials', 'expenses' => 2, 'total' => '£180.00', 'vat' => '£30.00', 'net' => '£150.00'], $totals['groups'][0]);
        $this->assertTrue($this->tools()->run('expense_totals', ['from' => '2026-09-30', 'to' => '2026-07-01', 'group_by' => 'month'])['is_error']);
    }

    public function test_invoices_are_only_created_once_the_person_confirms()
    {
        $this->fakeClaude([
            $this->message([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'prepare_invoice', 'input' => [
                'client_id' => $this->client->id,
                'reference' => 'PO-77',
                'items' => [
                    ['description' => 'Kitchen fitting', 'quantity' => '2', 'unit' => 'days', 'unit_price' => '250.00', 'vat_rate' => '20'],
                    ['description' => 'Skip hire', 'quantity' => '1', 'unit_price' => '80', 'vat_rate' => '0.00'],
                ],
            ]]], 'tool_use'),
            $this->message([['type' => 'text', 'text' => 'Here is the draft for Acme Builders.']]),
            $this->message([['type' => 'text', 'text' => 'Great.']]),
        ]);

        $response = $this->postJson('/assistant/messages', ['message' => 'Invoice Acme for 2 days kitchen fitting at £250 and a skip at £80'])
            ->assertOk()
            ->assertJsonPath('message.drafts.0.status', 'pending')
            ->assertJsonPath('message.drafts.0.client_name', 'Acme Builders')
            ->assertJsonPath('message.drafts.0.due_date', '2026-12-17')
            ->assertJsonPath('message.drafts.0.subtotal', 58000)
            ->assertJsonPath('message.drafts.0.tax_total', 10000)
            ->assertJsonPath('message.drafts.0.total', 68000);

        $this->assertStringContainsString('NOT been created', $this->request(1)['messages'][2]['content'][0]['content']);
        $this->assertSame(0, Document::count());

        $draftId = $response->json('message.drafts.0.id');

        $this->postJson("/assistant/drafts/{$draftId}/confirm")
            ->assertOk()
            ->assertJsonPath('draft.status', 'created')
            ->assertJsonPath('draft.document.number', 'INV-0001');

        $invoice = Document::sole();
        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        $this->assertSame($this->client->id, $invoice->client_id);
        $this->assertSame('PO-77', $invoice->reference);
        $this->assertSame(68000, $invoice->total);
        $this->assertSame(['Kitchen fitting', 'Skip hire'], $invoice->items->pluck('description')->all());

        // Confirming twice doesn't create a second invoice.
        $this->postJson("/assistant/drafts/{$draftId}/confirm")->assertOk();
        $this->assertSame(1, Document::count());

        // The assistant hears about it with the next question.
        $this->postJson('/assistant/messages', ['conversation_id' => $response->json('conversation_id'), 'message' => 'Thanks'])->assertOk();
        $lastUser = collect($this->request(2)['messages'])->last();
        $this->assertStringContainsString('created the prepared invoice for Acme Builders as INV-0001', $lastUser['content'][0]['text']);
        $this->assertSame('Thanks', $lastUser['content'][1]['text']);
    }

    public function test_drafts_can_be_cancelled()
    {
        $draft = $this->draft();

        $this->postJson("/assistant/drafts/{$draft->id}/cancel")->assertOk()->assertJsonPath('draft.status', 'cancelled');
        $this->postJson("/assistant/drafts/{$draft->id}/confirm")->assertOk()->assertJsonPath('draft.status', 'cancelled');

        $this->assertSame(0, Document::count());
    }

    public function test_confirming_respects_the_plan_invoice_limit()
    {
        $this->user->currentBusiness->forceFill(['trial_ends_at' => now()->subDay()])->save();

        for ($i = 0; $i < 5; $i++) {
            $this->createInvoice();
        }

        $draft = $this->draft();

        $this->postJson("/assistant/drafts/{$draft->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('upgrade_url', route('billing.edit'));

        $this->assertSame(5, Document::count());
        $this->assertSame(AssistantInvoiceDraft::PENDING, $draft->fresh()->status);
    }

    public function test_drafts_and_chats_are_private_to_the_person()
    {
        $draft = $this->draft();
        $teammate = User::factory()->create();
        $this->user->currentBusiness->addUser($teammate, BusinessRole::Member);
        $stranger = User::factory()->withBusiness()->create();

        foreach ([$teammate->fresh(), $stranger] as $other) {
            $this->actingAs($other);
            $this->postJson("/assistant/drafts/{$draft->id}/confirm")->assertNotFound();
            $this->postJson('/assistant/messages', ['conversation_id' => $draft->assistant_conversation_id, 'message' => 'Hi'])->assertNotFound();
            $this->delete("/assistant/{$draft->assistant_conversation_id}")->assertNotFound();
        }

        $this->assertSame(0, Document::count());
    }

    public function test_questions_can_be_limited_by_plan()
    {
        config(['plans.plans.pro.limits.assistant_messages_per_month' => 1]);

        $this->fakeClaude([$this->message([['type' => 'text', 'text' => 'Hello.']])]);

        $this->postJson('/assistant/messages', ['message' => 'Hi'])->assertOk();
        $this->postJson('/assistant/messages', ['message' => 'Hi again'])
            ->assertStatus(422)
            ->assertJsonPath('message', "You've used this month's assistant questions on the Pro plan. Upgrade to ask more.");

        $this->assertSame(1, $this->user->currentBusiness->planUsage()['assistant_messages_per_month']);
    }

    public function test_free_and_starter_plans_include_some_questions()
    {
        $this->assertSame(20, Plan::find('free')->limit('assistant_messages_per_month'));
        $this->assertSame(200, Plan::find('starter')->limit('assistant_messages_per_month'));
        $this->assertNull(Plan::find('pro')->limit('assistant_messages_per_month'));
    }

    public function test_refusals_and_failures_are_shown_without_saving_the_exchange()
    {
        $this->fakeClaude([$this->message([], 'refusal')]);

        $response = $this->postJson('/assistant/messages', ['message' => 'Something off topic'])->assertStatus(422);

        $this->assertStringContainsString("can't help with that", $response->json('message'));
        $this->assertSame([], AssistantConversation::findOrFail($response->json('conversation_id'))->transcript);
        $this->assertSame(0, AssistantMessage::count());
    }

    public function test_asking_is_off_without_an_api_key()
    {
        $this->postJson('/assistant/messages', ['message' => 'Hi'])->assertNotFound();
    }

    /**
     * @param  list<array<string, mixed>>  $responses
     */
    private function fakeClaude(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler(array_map(
            fn (array $body) => new Response(200, ['Content-Type' => 'application/json'], json_encode($body)),
            $responses,
        )));
        $stack->push(Middleware::history($this->history));

        $this->app->instance(Assistant::class, new Assistant('test-key', 'claude-opus-5-5', [
            'transporter' => new GuzzleClient(['handler' => $stack]),
            'maxRetries' => 0,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function request(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function message(array $content, string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'cache_read_input_tokens' => 100, 'cache_creation_input_tokens' => 0],
        ];
    }

    private function tools(): AssistantTools
    {
        $conversation = AssistantConversation::create(['user_id' => $this->user->id, 'transcript' => []]);

        return new AssistantTools($this->user->currentBusiness, $conversation);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function runTool(AssistantTools $tools, string $name, array $input): array
    {
        $result = $tools->run($name, $input);
        $this->assertFalse($result['is_error'], $result['content']);

        return json_decode($result['content'], true);
    }

    private function draft(): AssistantInvoiceDraft
    {
        $result = $this->tools()->run('prepare_invoice', [
            'client_id' => $this->client->id,
            'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => '100', 'vat_rate' => '20']],
        ]);

        return $result['draft'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createInvoice(array $overrides = [], bool $sent = false): Document
    {
        $this->post('/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => '2026-10-01',
            'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => '100', 'tax_rate' => '20.00']],
            ...$overrides,
        ])->assertSessionHasNoErrors();

        $invoice = Document::latest('id')->first();

        if ($sent) {
            $invoice->forceFill(['status' => DocumentStatus::Sent])->save();
        }

        return $invoice;
    }

    private function otherBusinessInvoice(): Document
    {
        $other = User::factory()->withBusiness()->create();
        $this->actingAs($other);

        $client = Client::factory()->create(['name' => 'Rival Ltd']);
        $this->post('/invoices', [
            'client_id' => $client->id,
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => '500', 'tax_rate' => '20.00']],
        ])->assertSessionHasNoErrors();

        $invoice = Document::latest('id')->first();
        $invoice->forceFill(['status' => DocumentStatus::Sent])->save();
        $invoice->load('client');

        $this->actingAs($this->user);

        return $invoice;
    }
}
