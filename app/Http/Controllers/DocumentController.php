<?php

namespace App\Http\Controllers;

use App\Actions\SaveDocument;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\RecurringFrequency;
use App\Http\Requests\DocumentRequest;
use App\Models\Client;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles estimates and invoices. The route's "type" default says which.
 */
class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $type = $this->type($request);
        $status = $request->string('status')->value();
        $search = $request->string('search')->trim()->value();

        $documents = Document::ofType($type)
            ->with('client:id,name')
            ->when($status === 'overdue', fn ($query) => $query
                ->where('status', DocumentStatus::Sent)
                ->whereDate('due_date', '<', today()))
            ->when($status && $status !== 'overdue', fn ($query) => $query->where('status', $status))
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('number', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhereHas('client', fn ($query) => $query->where('name', 'like', "%{$search}%"))))
            ->latest('issue_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Document $document) => $this->summary($document));

        return Inertia::render('documents/index', [
            'type' => $this->typeProps($type),
            'documents' => $documents,
            'filters' => ['status' => $status, 'search' => $search],
        ]);
    }

    public function create(Request $request): Response
    {
        $type = $this->type($request);
        $business = $request->user()->currentBusiness;

        $source = $request->integer('client_id') ? Client::find($request->integer('client_id')) : null;

        return Inertia::render('documents/form', [
            ...$this->formProps($request, $type),
            'document' => [
                'client_id' => $source?->id,
                'issue_date' => today()->toDateString(),
                'due_date' => today()->addDays($type === DocumentType::Invoice ? $business->payment_terms_days : 30)->toDateString(),
                'reference' => '',
                'notes' => $type === DocumentType::Invoice ? ($business->payment_details ?? '') : '',
                'items' => [],
            ],
        ]);
    }

    public function store(DocumentRequest $request, SaveDocument $saveDocument): RedirectResponse
    {
        $type = $this->type($request);

        $document = $saveDocument->handle(new Document, $type, $request->validated());

        return to_route("{$type->plural()}.show", $document);
    }

    public function show(Request $request, Document $document): Response
    {
        $type = $this->type($request, $document);

        $document->load(['client', 'items', 'payments', 'repeatSchedule', 'source:id,type,number', 'convertedTo:id,type,number,source_document_id']);

        return Inertia::render('documents/show', [
            'type' => $this->typeProps($type),
            'document' => [
                ...$this->summary($document),
                'due_date' => $document->due_date?->toDateString(),
                'reference' => $document->reference,
                'notes' => $document->notes,
                'subtotal' => $document->subtotal,
                'tax_total' => $document->tax_total,
                'sent_at' => $document->sent_at?->toIso8601String(),
                'client' => $document->client,
                'items' => $document->items,
                'vat_breakdown' => $document->vatBreakdown(),
                'source' => $document->source ? $this->link($document->source) : null,
                'converted_to' => $document->convertedTo ? $this->link($document->convertedTo) : null,
                'is_editable' => $document->isEditable(),
                'is_deletable' => $document->isDeletable(),
                'email_message' => DocumentActionController::defaultMessage($document),
                'amount_paid' => $document->amount_paid,
                'balance_due' => $document->balanceDue(),
                'payments' => $document->payments->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'paid_on' => $payment->paid_on->toDateString(),
                    'method' => $payment->method->label(),
                    'reference' => $payment->reference,
                ]),
                'repeat_schedule' => $document->repeatSchedule ? [
                    'frequency' => $document->repeatSchedule->frequency->label(),
                    'next_issue_date' => $document->repeatSchedule->next_issue_date->toDateString(),
                    'is_active' => $document->repeatSchedule->is_active,
                ] : null,
            ],
            'paymentMethods' => collect(PaymentMethod::manual())->map(fn (PaymentMethod $method) => [
                'value' => $method->value,
                'label' => $method->label(),
            ]),
            'frequencies' => collect(RecurringFrequency::cases())->map(fn (RecurringFrequency $frequency) => [
                'value' => $frequency->value,
                'label' => $frequency->label(),
            ]),
            'statuses' => collect($type->manualStatuses())->map(fn (DocumentStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
        ]);
    }

    public function edit(Request $request, Document $document): Response|RedirectResponse
    {
        $type = $this->type($request, $document);

        if (! $document->isEditable()) {
            return to_route("{$type->plural()}.show", $document);
        }

        $document->load('items');

        return Inertia::render('documents/form', [
            ...$this->formProps($request, $type),
            'document' => [
                'id' => $document->id,
                'number' => $document->number,
                'client_id' => $document->client_id,
                'issue_date' => $document->issue_date->toDateString(),
                'due_date' => $document->due_date?->toDateString(),
                'reference' => $document->reference ?? '',
                'notes' => $document->notes ?? '',
                'items' => $document->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit ?? '',
                    'unit_price' => number_format($item->unit_price / 100, 2, '.', ''),
                    'tax_rate' => $item->tax_rate,
                ]),
            ],
        ]);
    }

    public function update(DocumentRequest $request, Document $document, SaveDocument $saveDocument): RedirectResponse
    {
        $type = $this->type($request, $document);

        abort_unless($document->isEditable(), 403, 'This document can no longer be edited.');

        $saveDocument->handle($document, $type, $request->validated());

        return to_route("{$type->plural()}.show", $document);
    }

    public function updateStatus(Request $request, Document $document): RedirectResponse
    {
        $type = $this->type($request, $document);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_map(fn ($status) => $status->value, $type->manualStatuses()))],
        ]);

        abort_if($document->status === DocumentStatus::Invoiced, 403, 'This estimate has already been invoiced.');
        abort_if($document->amount_paid > 0, 403, 'Remove the payments on this invoice before changing its status.');

        $document->status = DocumentStatus::from($validated['status']);
        $document->save();

        return back();
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $type = $this->type($request, $document);

        abort_unless($document->isDeletable(), 403, 'Only drafts can be deleted. Void the document instead.');

        $document->delete();

        return to_route("{$type->plural()}.index");
    }

    private function type(Request $request, ?Document $document = null): DocumentType
    {
        $type = DocumentType::from($request->route('type'));

        abort_if($document && $document->type !== $type, 404);

        return $type;
    }

    /**
     * @return array<string, string>
     */
    private function typeProps(DocumentType $type): array
    {
        return ['value' => $type->value, 'label' => $type->label(), 'plural' => $type->plural()];
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(Request $request, DocumentType $type): array
    {
        $business = $request->user()->currentBusiness;

        return [
            'type' => $this->typeProps($type),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'email']),
            'products' => Product::with('taxRate:id,rate')->orderBy('name')->get()->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'unit' => $product->unit,
                'unit_price' => number_format($product->unit_price / 100, 2, '.', ''),
                'tax_rate' => $product->taxRate?->rate,
            ]),
            'taxRates' => $business->taxRates()->orderByDesc('rate')->get(['id', 'name', 'rate', 'is_default']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Document $document): array
    {
        return [
            'id' => $document->id,
            'number' => $document->number,
            'status' => $document->status->value,
            'is_overdue' => $document->isOverdue(),
            'balance_due' => $document->balanceDue(),
            'issue_date' => $document->issue_date->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'client_name' => $document->client?->name,
            'total' => $document->total,
            'currency' => $document->currency,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function link(Document $document): array
    {
        return [
            'number' => $document->number,
            'url' => route("{$document->type->plural()}.show", $document),
        ];
    }
}
