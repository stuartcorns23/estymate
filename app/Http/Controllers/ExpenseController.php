<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseCategory;
use App\Http\Requests\ExpenseRequest;
use App\Models\Expense;
use App\Services\ExpenseReader\ClaudeExpenseReader;
use App\Services\ExpenseReader\ExpenseReader;
use App\Services\ExpenseReader\ExpenseReaderFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', Rule::enum(ExpenseCategory::class)],
        ]);
        $search = trim($filters['search'] ?? '');
        $category = $filters['category'] ?? null;

        $query = Expense::query()
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('supplier', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->when($category, fn ($query) => $query->where('category', $category));

        return Inertia::render('expenses/index', [
            'expenses' => (clone $query)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'totals' => [
                'total' => (int) (clone $query)->sum('total'),
                'vat' => (int) (clone $query)->sum('vat_amount'),
            ],
            'filters' => ['search' => $search, 'category' => $category ?? ''],
            'categories' => ExpenseCategory::options(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    /**
     * Expenses aren't limited by subscription plan, unlike clients, estimates and invoices.
     */
    public function store(ExpenseRequest $request): RedirectResponse
    {
        $expense = new Expense($request->expenseData());
        $expense->business_id = $request->user()->current_business_id;
        $this->storeAttachment($request, $expense);
        $expense->save();

        return to_route('expenses.index');
    }

    public function edit(Request $request, Expense $expense): Response
    {
        return $this->form($request, $expense);
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $expense->fill($request->expenseData());

        if ($request->boolean('remove_attachment')) {
            $expense->deleteAttachment();
        }

        $this->storeAttachment($request, $expense);
        $expense->save();

        return to_route('expenses.index');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $expense->delete();

        return to_route('expenses.index');
    }

    /**
     * Download the uploaded invoice or receipt.
     */
    public function attachment(Expense $expense): StreamedResponse
    {
        abort_unless($expense->attachment_path && Storage::disk(Expense::DISK)->exists($expense->attachment_path), 404);

        return Storage::disk(Expense::DISK)->download($expense->attachment_path, $expense->attachment_name);
    }

    /**
     * Read a dropped invoice or receipt and suggest the expense's details.
     * Nothing is saved: the form fills in and the person checks it.
     */
    public function scan(Request $request, ExpenseReader $reader): JsonResponse
    {
        abort_unless($reader->available(), 404);

        $request->validate([
            'attachment' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,gif', 'max:10240'],
        ]);

        $file = $request->file('attachment');

        try {
            $fields = $reader->read($file->get(), $file->getMimeType());
        } catch (ExpenseReaderFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['fields' => $fields]);
    }

    private function form(Request $request, ?Expense $expense): Response
    {
        return Inertia::render('expenses/form', [
            'canScan' => app(ExpenseReader::class)->available(),
            'scanTypes' => ClaudeExpenseReader::MIME_TYPES,
            'expense' => $expense,
            'categories' => ExpenseCategory::options(),
            'taxRates' => $request->user()->currentBusiness->documentTaxRates()->orderByDesc('rate')->get(['id', 'name', 'rate', 'is_default']),
            'suppliers' => Expense::query()->distinct()->orderBy('supplier')->limit(200)->pluck('supplier'),
        ]);
    }

    /**
     * Save a newly uploaded file, replacing any earlier one.
     */
    private function storeAttachment(ExpenseRequest $request, Expense $expense): void
    {
        if (! $file = $request->file('attachment')) {
            return;
        }

        // Write the contents rather than using store(): store() opens the upload by its real
        // path, which PHP can't resolve for the temp folder on some Windows setups (Laragon).
        $path = "expenses/{$expense->business_id}/".Str::random(40).'.'.($file->guessExtension() ?: $file->getClientOriginalExtension());
        Storage::disk(Expense::DISK)->put($path, $file->get());

        $expense->deleteAttachment();
        $expense->attachment_path = $path;
        $expense->attachment_name = $file->getClientOriginalName();
    }
}
