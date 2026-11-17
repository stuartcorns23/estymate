<?php

namespace App\Tax;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Business;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Totals for one UK tax year, grouped the way HMRC's self-employment pages (SA103) ask for them.
 *
 * Amounts are in pence. VAT-registered businesses report turnover and expenses without VAT;
 * other businesses report expenses including the VAT they paid, since they can't reclaim it.
 */
class TaxReturnReport
{
    /**
     * Turnover below this lets you give total expenses as one figure (SA103F box 31, SA103S box 20).
     */
    public const SINGLE_FIGURE_THRESHOLD = 9_000_000;

    /**
     * The trading allowance: income up to this needn't be declared.
     */
    public const TRADING_ALLOWANCE = 100_000;

    public function __construct(
        private Business $business,
        private TaxYear $taxYear,
        private AccountingBasis $basis,
    ) {}

    public function vatRegistered(): bool
    {
        return filled($this->business->vat_number);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $quarters = array_map(fn (array $quarter) => [
            'label' => $quarter['label'],
            'turnover' => 0,
            'expenses' => 0,
        ], $this->taxYear->quarters());

        [$turnover, $outputVat, $salesCount] = $this->income($quarters);
        [$boxes, $totalExpenses, $inputVat, $expenseCount] = $this->expenses($quarters);

        return [
            'tax_year' => [
                'start_year' => $this->taxYear->startYear,
                'label' => $this->taxYear->label(),
                'period' => $this->taxYear->period(),
                'start' => $this->taxYear->start()->toDateString(),
                'end' => $this->taxYear->end()->toDateString(),
                'filing_deadline' => $this->taxYear->filingDeadline()->toDateString(),
                'in_progress' => $this->taxYear->end()->isFuture(),
            ],
            'basis' => $this->basis->value,
            'basis_label' => $this->basis->label(),
            'vat_registered' => $this->vatRegistered(),
            'currency' => $this->business->currency,
            'business_name' => $this->business->name,
            'turnover' => $turnover,
            'sales_count' => $salesCount,
            'expense_boxes' => $boxes,
            'total_expenses' => $totalExpenses,
            'expense_count' => $expenseCount,
            'net_profit' => $turnover - $totalExpenses,
            'single_figure_allowed' => $turnover < self::SINGLE_FIGURE_THRESHOLD,
            'under_trading_allowance' => $turnover > 0 && $turnover <= self::TRADING_ALLOWANCE,
            'vat' => $this->vatRegistered() ? [
                'output' => $outputVat,
                'input' => $inputVat,
                'net' => $outputVat - $inputVat,
            ] : null,
            'quarters' => array_map(fn (array $quarter) => [
                ...$quarter,
                'profit' => $quarter['turnover'] - $quarter['expenses'],
            ], $quarters),
            'unpaid_expenses' => $this->unpaidExpenses(),
        ];
    }

    /**
     * Turnover (without VAT) and the VAT charged on it.
     *
     * @param  list<array{label: string, turnover: int, expenses: int}>  $quarters
     * @return array{int, int, int}
     */
    private function income(array &$quarters): array
    {
        $turnover = 0;
        $vat = 0;
        $count = 0;

        if ($this->basis === AccountingBasis::Cash) {
            $payments = Payment::query()
                ->whereBelongsTo($this->business)
                ->tap(fn ($query) => $this->inYear($query, 'paid_on'))
                ->with(['document' => fn ($query) => $query->withTrashed()->select('id', 'total', 'tax_total')])
                ->get(['id', 'document_id', 'amount', 'paid_on']);

            foreach ($payments as $payment) {
                $document = $payment->document;
                // A part payment carries the invoice's VAT in proportion.
                $paymentVat = $document && $document->total > 0
                    ? (int) round($payment->amount * $document->tax_total / $document->total)
                    : 0;

                $net = $payment->amount - $paymentVat;
                $turnover += $net;
                $vat += $paymentVat;
                $count++;
                $quarters[$this->quarterOf($payment->paid_on)]['turnover'] += $net;
            }

            return [$turnover, $vat, $count];
        }

        $invoices = Document::query()
            ->whereBelongsTo($this->business)
            ->ofType(DocumentType::Invoice)
            ->whereIn('status', [DocumentStatus::Sent, DocumentStatus::Paid])
            ->tap(fn ($query) => $this->inYear($query, 'issue_date'))
            ->get(['id', 'issue_date', 'subtotal', 'tax_total']);

        foreach ($invoices as $invoice) {
            $turnover += $invoice->subtotal;
            $vat += $invoice->tax_total;
            $count++;
            $quarters[$this->quarterOf($invoice->issue_date)]['turnover'] += $invoice->subtotal;
        }

        return [$turnover, $vat, $count];
    }

    /**
     * Allowable expenses grouped into SA103 boxes, in box order.
     *
     * @param  list<array{label: string, turnover: int, expenses: int}>  $quarters
     * @return array{list<array<string, mixed>>, int, int, int}
     */
    private function expenses(array &$quarters): array
    {
        $dateColumn = $this->basis === AccountingBasis::Cash ? 'paid_on' : 'date';

        $expenses = Expense::query()
            ->whereBelongsTo($this->business)
            ->tap(fn ($query) => $this->inYear($query, $dateColumn))
            ->get(['id', 'date', 'paid_on', 'category', 'total', 'vat_amount']);

        $boxes = [];
        $total = 0;
        $inputVat = 0;

        foreach ($expenses as $expense) {
            $amount = $this->vatRegistered() ? $expense->total - $expense->vat_amount : $expense->total;
            $box = Sa103Box::forCategory($expense->category);

            $boxes[$box->value] ??= [
                'box' => $box->value,
                'label' => $box->label(),
                'full_form_box' => $box->fullFormBox(),
                'short_form_box' => $box->shortFormBox(),
                'amount' => 0,
                'categories' => [],
            ];
            $boxes[$box->value]['amount'] += $amount;

            $category = &$boxes[$box->value]['categories'][$expense->category->value];
            $category ??= ['label' => $expense->category->label(), 'amount' => 0, 'count' => 0];
            $category['amount'] += $amount;
            $category['count']++;
            unset($category);

            $total += $amount;
            $inputVat += $this->vatRegistered() ? $expense->vat_amount : 0;
            $quarters[$this->quarterOf($expense->{$dateColumn})]['expenses'] += $amount;
        }

        usort($boxes, fn (array $a, array $b) => $a['full_form_box'] <=> $b['full_form_box']);

        $boxes = array_map(fn (array $box) => [...$box, 'categories' => array_values($box['categories'])], $boxes);

        return [$boxes, $total, $inputVat, $expenses->count()];
    }

    /**
     * Expenses dated in the year with no "paid on" date. The cash basis leaves them out.
     *
     * @return array{count: int, total: int}|null
     */
    private function unpaidExpenses(): ?array
    {
        if ($this->basis !== AccountingBasis::Cash) {
            return null;
        }

        $query = Expense::query()
            ->whereBelongsTo($this->business)
            ->whereNull('paid_on')
            ->tap(fn ($query) => $this->inYear($query, 'date'));

        $count = (clone $query)->count();

        return $count > 0 ? ['count' => $count, 'total' => (int) $query->sum('total')] : null;
    }

    /**
     * Limit a query to dates in the tax year. whereDate copes with dates stored with a time.
     *
     * @param  Builder<Model>  $query
     */
    private function inYear($query, string $column): void
    {
        $query->whereDate($column, '>=', $this->taxYear->start()->toDateString())
            ->whereDate($column, '<=', $this->taxYear->end()->toDateString());
    }

    private function quarterOf(CarbonInterface $date): int
    {
        foreach ($this->taxYear->quarters() as $index => $quarter) {
            if ($date->toDateString() <= $quarter['end']->toDateString()) {
                return $index;
            }
        }

        return 3;
    }
}
