<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Payment;
use App\Tax\Period;
use App\Tax\TaxYear;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $period = Period::fromRequest($request, Period::CurrentTaxYear);
        $invoices = fn () => $period->apply(Document::ofType(DocumentType::Invoice), 'issue_date');
        $unpaid = $invoices()->where('status', DocumentStatus::Sent);
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
                'paid' => (int) $period->apply(Payment::query(), 'paid_on')->sum('amount'),
                'drafts' => $invoices()->where('status', DocumentStatus::Draft)->count(),
                'spent' => (int) $period->apply(Expense::query(), 'date')->sum('total'),
            ],
            'cashflow' => $this->cashflow($period),
            'expenseCategories' => $this->expenseCategories($period),
            'overdueInvoices' => $summary((clone $overdue)->orderBy('due_date')),
            'openEstimates' => $summary($period->apply(Document::ofType(DocumentType::Estimate), 'issue_date')
                ->whereIn('status', [DocumentStatus::Sent, DocumentStatus::Accepted])
                ->latest('issue_date')),
            'usage' => $request->user()->currentBusiness->usage(),
            'period' => $period->value,
            'periods' => Period::options(),
        ]);
    }

    /**
     * Money in (payments received) and money out (expenses): month by month across a tax year,
     * or tax year by tax year for lifetime.
     *
     * Grouped in PHP so it works the same on SQLite and MySQL.
     *
     * @return list<array{key: string, label: string, title: string, income: int, expenses: int}>
     */
    private function cashflow(Period $period): array
    {
        $taxYear = $period->taxYear();
        $buckets = [];

        if ($taxYear) {
            for ($i = 0, $month = $taxYear->start()->startOfMonth(); $i < 12; $i++, $month = $month->addMonth()) {
                $buckets[$month->format('Y-m')] = ['key' => $month->format('Y-m'), 'label' => $month->format('M'), 'title' => $month->format('F Y'), 'income' => 0, 'expenses' => 0];
            }
            // 1 to 5 April at the end of the tax year has no bar of its own, so it counts towards March.
            $bucket = fn (CarbonInterface $date) => isset($buckets[$date->format('Y-m')]) ? $date->format('Y-m') : array_key_last($buckets);
        } else {
            $first = collect([Payment::min('paid_on'), Expense::min('date')])->filter()->map(fn ($date) => CarbonImmutable::parse($date))->min();
            $current = TaxYear::current()->startYear;

            foreach (range($first ? TaxYear::containing($first)->startYear : $current, $current) as $year) {
                $label = (new TaxYear($year))->label();
                $buckets[(string) $year] = ['key' => (string) $year, 'label' => $label, 'title' => "{$label} tax year", 'income' => 0, 'expenses' => 0];
            }
            $bucket = fn (CarbonInterface $date) => (string) TaxYear::containing($date)->startYear;
        }

        foreach ($period->apply(Payment::query(), 'paid_on')->get(['paid_on', 'amount']) as $payment) {
            $buckets[$bucket($payment->paid_on)]['income'] += $payment->amount;
        }

        foreach ($period->apply(Expense::query(), 'date')->get(['date', 'total']) as $expense) {
            $buckets[$bucket($expense->date)]['expenses'] += $expense->total;
        }

        return array_values($buckets);
    }

    /**
     * Expenses in the period by category, biggest first.
     *
     * @return list<array{category: string, label: string, total: int}>
     */
    private function expenseCategories(Period $period): array
    {
        return $period->apply(Expense::query(), 'date')
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
