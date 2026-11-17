<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Payment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $unpaid = Document::ofType(DocumentType::Invoice)->where('status', DocumentStatus::Sent);
        $overdue = (clone $unpaid)->whereDate('due_date', '<', today());

        $summary = fn ($query) => $query
            ->with('client:id,name')
            ->limit(5)
            ->get()
            ->map(fn (Document $document) => [
                'id' => $document->id,
                'number' => $document->number,
                'client_name' => $document->client?->name,
                'date' => ($document->due_date ?? $document->issue_date)->toDateString(),
                'amount' => $document->type === DocumentType::Invoice ? $document->balanceDue() : $document->total,
                'currency' => $document->currency,
            ]);

        return Inertia::render('dashboard', [
            'totals' => [
                'outstanding' => (int) (clone $unpaid)->selectRaw('coalesce(sum(total - amount_paid), 0) as owed')->value('owed'),
                'overdue' => (int) (clone $overdue)->selectRaw('coalesce(sum(total - amount_paid), 0) as owed')->value('owed'),
                'paid_this_month' => (int) Payment::where('paid_on', '>=', today()->startOfMonth())->sum('amount'),
                'drafts' => Document::ofType(DocumentType::Invoice)->where('status', DocumentStatus::Draft)->count(),
                'spent_this_month' => (int) Expense::where('date', '>=', today()->startOfMonth())->sum('total'),
            ],
            'cashflow' => $this->cashflow(),
            'expenseCategories' => $this->expenseCategories(),
            'overdueInvoices' => $summary((clone $overdue)->orderBy('due_date')),
            'openEstimates' => $summary(Document::ofType(DocumentType::Estimate)
                ->whereIn('status', [DocumentStatus::Sent, DocumentStatus::Accepted])
                ->latest('issue_date')),
            'usage' => $request->user()->currentBusiness->usage(),
        ]);
    }

    /**
     * Money in (payments received) and money out (expenses) for each of the last 12 months.
     *
     * Grouped in PHP so it works the same on SQLite and MySQL.
     *
     * @return list<array{month: string, income: int, expenses: int}>
     */
    private function cashflow(): array
    {
        $from = today()->startOfMonth()->subMonths(11);
        $months = [];

        for ($month = $from->copy(); $month <= today(); $month->addMonth()) {
            $months[$month->format('Y-m')] = ['month' => $month->format('Y-m'), 'income' => 0, 'expenses' => 0];
        }

        foreach (Payment::where('paid_on', '>=', $from)->get(['paid_on', 'amount']) as $payment) {
            $months[$payment->paid_on->format('Y-m')]['income'] += $payment->amount;
        }

        foreach (Expense::where('date', '>=', $from)->get(['date', 'total']) as $expense) {
            $months[$expense->date->format('Y-m')]['expenses'] += $expense->total;
        }

        return array_values($months);
    }

    /**
     * Expenses over the last 12 months by category, biggest first.
     *
     * @return list<array{category: string, label: string, total: int}>
     */
    private function expenseCategories(): array
    {
        return Expense::where('date', '>=', today()->startOfMonth()->subMonths(11))
            ->selectRaw('category, sum(total) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn (Expense $row) => [
                'category' => $row->category->value,
                'label' => $row->category->label(),
                'total' => (int) $row->total,
            ])
            ->all();
    }
}
