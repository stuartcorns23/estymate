<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Support\ExpenseImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Import expenses from a CSV: pick a file, check the columns and rows, then import.
 *
 * Like the client and invoice imports, the browser reads the file and sends its text.
 * Expenses aren't limited by any plan, so every ticked row is imported.
 */
class ExpenseImportController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('expenses/import', [
            'fields' => ExpenseImport::fieldOptions(),
            'categories' => ExpenseCategory::options(),
            'maxBytes' => ExpenseImport::MAX_BYTES,
        ]);
    }

    /**
     * Read the CSV and say what importing it would do, without saving anything.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate($this->rules(mappingRequired: false));

        $import = ExpenseImport::fromCsv($request->string('csv')->value());
        $mapping = $this->mapping($request, $import);

        return response()->json([
            'headers' => $import->headers,
            'format' => $import->format(),
            'mapping' => $mapping,
            'samples' => $import->samples(),
            'rows' => $import->analyse($mapping, Expense::all(), $this->currency($request)),
        ]);
    }

    /**
     * Import the ticked rows.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            ...$this->rules(mappingRequired: true),
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['integer'],
        ], ['rows.required' => 'Tick at least one expense to import.']);

        $import = ExpenseImport::fromCsv($request->string('csv')->value());
        $mapping = $this->mapping($request, $import);
        $chosen = array_flip(array_map('intval', $request->input('rows')));
        $currency = $this->currency($request);

        $imported = DB::transaction(function () use ($import, $mapping, $chosen, $currency) {
            // Re-check the rows here rather than trusting the preview.
            $rows = collect($import->analyse($mapping, Expense::all(), $currency))
                ->filter(fn ($row) => isset($chosen[$row['row']]) && $row['status'] !== 'invalid');

            foreach ($rows as $row) {
                $attributes = $row['attributes'];
                $expense = new Expense(collect($attributes)->except('currency')->all());
                $expense->currency = $attributes['currency'];
                $expense->save();
            }

            return $rows->count();
        });

        return to_route('expenses.index')->with('status', $imported === 1 ? 'Imported 1 expense.' : "Imported {$imported} expenses.");
    }

    private function currency(Request $request): string
    {
        return $request->user()->currentBusiness->currency ?? 'GBP';
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $mappingRequired): array
    {
        return [
            'csv' => ['required', 'string', 'max:'.ExpenseImport::MAX_BYTES],
            'mapping' => [$mappingRequired ? 'required' : 'nullable', 'array'],
            'mapping.*' => ['nullable', Rule::in(array_keys(ExpenseImport::FIELDS))],
        ];
    }

    /**
     * The mapping the person chose, or our best guess when they haven't chosen yet.
     *
     * @return list<string|null>
     */
    private function mapping(Request $request, ExpenseImport $import): array
    {
        $mapping = $request->input('mapping');

        if (! is_array($mapping) || count($mapping) !== count($import->headers)) {
            return $import->guessMapping();
        }

        return array_map(fn ($field) => $field ?: null, array_values($mapping));
    }
}
