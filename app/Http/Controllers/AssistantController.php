<?php

namespace App\Http\Controllers;

use App\Actions\SaveDocument;
use App\Billing\PlanLimitReached;
use App\Enums\DocumentType;
use App\Models\AssistantConversation;
use App\Models\AssistantInvoiceDraft;
use App\Models\AssistantMessage;
use App\Models\Document;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\AssistantFailed;
use App\Services\Assistant\AssistantTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-app assistant chat. Each person only sees their own chats in their current business.
 */
class AssistantController extends Controller
{
    public function index(Request $request, Assistant $assistant): Response
    {
        $conversations = $this->conversations($request);
        $current = $request->integer('chat') ? (clone $conversations)->find($request->integer('chat')) : null;

        return Inertia::render('assistant/index', [
            'available' => $assistant->available(),
            'conversations' => $conversations->latest('updated_at')->limit(30)->get(['id', 'title', 'updated_at']),
            'conversation' => $current ? [
                'id' => $current->id,
                'title' => $current->title,
                'messages' => $current->messages()->with('drafts.document')->get()->map(fn (AssistantMessage $message) => $this->message($request, $message)),
            ] : null,
            'maxQuestions' => AssistantConversation::MAX_QUESTIONS,
        ]);
    }

    public function store(Request $request, Assistant $assistant): JsonResponse
    {
        abort_unless($assistant->available(), 404);

        $validated = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $business = $request->user()->currentBusiness;

        try {
            $business->ensureWithinLimit('assistant_messages_per_month');
        } catch (PlanLimitReached $e) {
            return response()->json(['message' => "You've used this month's assistant questions on the {$e->plan->name} plan. Upgrade to ask more."], 422);
        }

        $conversation = isset($validated['conversation_id'])
            ? $this->conversations($request)->findOrFail($validated['conversation_id'])
            : AssistantConversation::create(['user_id' => $request->user()->id, 'transcript' => []]);

        if ($conversation->messages()->where('role', 'user')->count() >= AssistantConversation::MAX_QUESTIONS) {
            return response()->json(['message' => 'This chat is full. Start a new chat to keep going.'], 422);
        }

        // Answers that look things up can take longer than PHP's default 30 seconds.
        set_time_limit(180);

        try {
            $reply = $assistant->reply($conversation, trim($validated['message']));
        } catch (AssistantFailed $e) {
            return response()->json(['message' => $e->getMessage(), 'conversation_id' => $conversation->id], 422);
        }

        $reply->load('drafts.document');

        return response()->json([
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
            'message' => $this->message($request, $reply),
        ]);
    }

    public function destroy(Request $request, AssistantConversation $conversation): RedirectResponse
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);

        $conversation->delete();

        return to_route('assistant.index');
    }

    /**
     * Create the invoice the assistant prepared, after the person has checked it.
     */
    public function confirm(Request $request, AssistantInvoiceDraft $draft, SaveDocument $saveDocument): JsonResponse
    {
        $this->authorizeDraft($request, $draft);
        $business = $request->user()->currentBusiness;

        if ($draft->status !== AssistantInvoiceDraft::PENDING) {
            return response()->json(['draft' => AssistantTools::preview($business, $draft)]);
        }

        try {
            $business->ensureWithinLimit('invoices_per_month');
        } catch (PlanLimitReached $e) {
            return response()->json([
                'message' => "The {$e->plan->name} plan includes {$e->plan->limit('invoices_per_month')} invoices a month. Upgrade to create more.",
                'upgrade_url' => route('billing.edit'),
            ], 422);
        }

        // Check again: the client or VAT rates may have changed since the draft was prepared.
        $validator = Validator::make($draft->data, AssistantTools::invoiceRules($business));

        if ($validator->fails()) {
            return response()->json(['message' => 'This draft is out of date: '.implode(' ', $validator->errors()->all()).' Ask the assistant to prepare it again.'], 422);
        }

        $document = $saveDocument->handle(new Document, DocumentType::Invoice, $validator->validated());

        $draft->status = AssistantInvoiceDraft::CREATED;
        $draft->document()->associate($document);
        $draft->save();

        return response()->json(['draft' => AssistantTools::preview($business, $draft)]);
    }

    public function cancel(Request $request, AssistantInvoiceDraft $draft): JsonResponse
    {
        $this->authorizeDraft($request, $draft);

        if ($draft->status === AssistantInvoiceDraft::PENDING) {
            $draft->update(['status' => AssistantInvoiceDraft::CANCELLED]);
        }

        return response()->json(['draft' => AssistantTools::preview($request->user()->currentBusiness, $draft)]);
    }

    /**
     * @return Builder<AssistantConversation>
     */
    private function conversations(Request $request)
    {
        return AssistantConversation::query()
            ->where('business_id', $request->user()->current_business_id)
            ->where('user_id', $request->user()->id);
    }

    private function authorizeDraft(Request $request, AssistantInvoiceDraft $draft): void
    {
        // The business scope already hides other businesses' drafts; this keeps a teammate's chats private too.
        abort_unless(
            $draft->business_id === $request->user()->current_business_id && $draft->conversation->user_id === $request->user()->id,
            404,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function message(Request $request, AssistantMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'body' => $message->body,
            'drafts' => $message->drafts->map(fn (AssistantInvoiceDraft $draft) => AssistantTools::preview($request->user()->currentBusiness, $draft))->all(),
        ];
    }
}
