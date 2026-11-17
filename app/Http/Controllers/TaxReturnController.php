<?php

namespace App\Http\Controllers;

use App\Billing\Plan;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Payment;
use App\Support\Money;
use App\Tax\AccountingBasis;
use App\Tax\TaxReturnReport;
use App\Tax\TaxYear;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Figures to help fill in the self-employment pages of a UK Self Assessment tax return.
 */
class TaxReturnController extends Controller
{
    public function index(Request $request): Response
    {
        [$taxYear, $basis] = $this->options($request);
        $included = $this->included($request);

        return Inertia::render('tax-return/index', [
            'included' => $included,
            'requiredPlan' => Plan::cheapestWith('tax_return')?->name,
            'report' => $included ? $this->report($request, $taxYear, $basis)->toArray() : null,
            'years' => array_map(fn (TaxYear $year) => ['value' => (string) $year->startYear, 'label' => $year->label()], $this->years()),
            'bases' => array_map(fn (AccountingBasis $basis) => ['value' => $basis->value, 'label' => $basis->label()], AccountingBasis::cases()),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$taxYear, $basis] = $this->options($request);
        $report = $this->report($request, $taxYear, $basis)->toArray();
        $pounds = fn (int $pence) => number_format($pence / 100, 2, '.', '');

        return response()->streamDownload(function () use ($report, $pounds) {
            $out = fopen('php://output', 'w');
            $row = fn (array $fields) => fputcsv($out, $fields, escape: '');

            $row(['Tax year', $report['tax_year']['label'], $report['tax_year']['period']]);
            $row(['Basis', $report['basis_label']]);
            $row(['Amounts', $report['vat_registered'] ? 'Excluding VAT' : 'Including VAT paid on expenses']);
            $row([]);
            $row(['SA103F box', 'SA103S box', 'Item', 'Amount (GBP)']);
            $row(['15', '9', 'Turnover', $pounds($report['turnover'])]);

            foreach ($report['expense_boxes'] as $box) {
                $row([$box['full_form_box'], $box['short_form_box'], $box['label'], $pounds($box['amount'])]);
            }

            $row(['31', '20', 'Total allowable expenses', $pounds($report['total_expenses'])]);
            $row([$report['net_profit'] >= 0 ? '47' : '48', '', $report['net_profit'] >= 0 ? 'Net profit' : 'Net loss', $pounds(abs($report['net_profit']))]);

            if ($report['vat']) {
                $row([]);
                $row(['', '', 'VAT on sales', $pounds($report['vat']['output'])]);
                $row(['', '', 'VAT on expenses', $pounds($report['vat']['input'])]);
                $row(['', '', 'Net VAT', $pounds($report['vat']['net'])]);
            }

            $row([]);
            $row(['Quarter', 'Turnover', 'Expenses', 'Profit']);
            foreach ($report['quarters'] as $quarter) {
                $row([$quarter['label'], $pounds($quarter['turnover']), $pounds($quarter['expenses']), $pounds($quarter['profit'])]);
            }

            $row([]);
            $row(['Expense breakdown']);
            $row(['SA103F box', 'Category', 'Expenses', 'Amount (GBP)']);
            foreach ($report['expense_boxes'] as $box) {
                foreach ($box['categories'] as $category) {
                    $row([$box['full_form_box'], $category['label'], $category['count'], $pounds($category['amount'])]);
                }
            }

            $row([]);
            $row(['A guide to help with your return, not tax advice. Check the figures before you file.']);

            fclose($out);
        }, "tax-return-{$taxYear->label()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pdf(Request $request): HttpResponse
    {
        [$taxYear, $basis] = $this->options($request);
        $report = $this->report($request, $taxYear, $basis)->toArray();

        $pdf = Pdf::loadView('pdf.tax-return', [
            'report' => $report,
            'money' => fn (int $pence) => Money::format($pence, $report['currency']),
            'date' => fn (string $date) => CarbonImmutable::parse($date)->format('j F Y'),
        ])->setPaper('a4');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="tax-return-'.$taxYear->label().'.pdf"',
        ]);
    }

    /**
     * @return array{TaxYear, AccountingBasis}
     */
    private function options(Request $request): array
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:'.TaxYear::current()->startYear],
            'basis' => ['nullable', Rule::enum(AccountingBasis::class)],
        ]);

        return [
            isset($validated['year']) ? new TaxYear((int) $validated['year']) : TaxYear::lastCompleted(),
            AccountingBasis::tryFrom($validated['basis'] ?? '') ?? AccountingBasis::Cash,
        ];
    }

    private function included(Request $request): bool
    {
        return $request->user()->currentBusiness->plan()->hasFeature('tax_return');
    }

    /**
     * The report for a plan that includes it. Exports on other plans are refused.
     */
    private function report(Request $request, TaxYear $taxYear, AccountingBasis $basis): TaxReturnReport
    {
        abort_unless($this->included($request), 403, 'Tax return figures are not included in your plan.');

        return new TaxReturnReport($request->user()->currentBusiness, $taxYear, $basis);
    }

    /**
     * Every tax year from the business's first record to now, newest first.
     *
     * @return list<TaxYear>
     */
    private function years(): array
    {
        $first = collect([
            Payment::min('paid_on'),
            Expense::min('date'),
            Document::min('issue_date'),
        ])->filter()->map(fn ($date) => CarbonImmutable::parse($date))->min();

        $current = TaxYear::current()->startYear;
        $from = min($first ? TaxYear::containing($first)->startYear : $current, TaxYear::lastCompleted()->startYear);

        return array_map(fn (int $year) => new TaxYear($year), range($current, max($from, $current - 10)));
    }
}
