<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\RecurringFrequency;
use App\Models\Document;
use App\Models\RecurringInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RecurringInvoiceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('recurring-invoices/index', [
            'schedules' => RecurringInvoice::with(['template' => fn ($query) => $query->with('client:id,name')])
                ->withCount('invoices')
                ->orderByDesc('is_active')
                ->orderBy('next_issue_date')
                ->get()
                ->map(fn (RecurringInvoice $schedule) => [
                    'id' => $schedule->id,
                    'client_name' => $schedule->template->client?->name,
                    'template' => ['id' => $schedule->template->id, 'number' => $schedule->template->number],
                    'total' => $schedule->template->total,
                    'currency' => $schedule->template->currency,
                    'frequency' => $schedule->frequency->label(),
                    'next_issue_date' => $schedule->next_issue_date->toDateString(),
                    'ends_on' => $schedule->ends_on?->toDateString(),
                    'auto_send' => $schedule->auto_send,
                    'is_active' => $schedule->is_active,
                    'invoices_count' => $schedule->invoices_count,
                ]),
        ]);
    }

    /**
     * Start repeating an invoice on a schedule.
     */
    public function store(Request $request, Document $document): RedirectResponse
    {
        abort_unless($document->type === DocumentType::Invoice, 404);
        abort_if($document->status === DocumentStatus::Void, 403);
        abort_if($document->repeatSchedule()->exists(), 403, 'This invoice already repeats.');

        $document->repeatSchedule()->create($this->validated($request));

        return to_route('recurring-invoices.index');
    }

    public function update(Request $request, RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $recurringInvoice->update($this->validated($request, partial: true));

        return back();
    }

    public function destroy(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $recurringInvoice->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'frequency' => [$required, Rule::enum(RecurringFrequency::class)],
            'next_issue_date' => [$required, 'date', 'after_or_equal:today'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:next_issue_date'],
            'auto_send' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
