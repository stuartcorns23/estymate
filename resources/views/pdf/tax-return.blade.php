<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Tax return figures {{ $report['tax_year']['label'] }}</title>
<style>
    @page { margin: 40px 48px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.45; }
    h1 { font-size: 18px; margin: 0 0 2px; }
    h2 { font-size: 12px; margin: 22px 0 6px; }
    .muted { color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 5px 6px; }
    td { padding: 5px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .total td { font-weight: bold; background: #f9fafb; }
    .note { margin-top: 24px; padding: 8px 10px; background: #f3f4f6; font-size: 9px; }
</style>
</head>
<body>
    <h1>Tax return figures {{ $report['tax_year']['label'] }}</h1>
    <div class="muted">
        {{ $report['business_name'] }} · {{ $report['tax_year']['period'] }} · {{ $report['basis_label'] }} ·
        {{ $report['vat_registered'] ? 'Amounts exclude VAT' : 'Expenses include VAT paid' }}
    </div>

    <h2>Self-employment (SA103)</h2>
    <table>
        <thead>
            <tr><th style="width: 55px">SA103F</th><th style="width: 55px">SA103S</th><th>Item</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            <tr><td>15</td><td>9</td><td>Turnover</td><td class="num">{{ $money($report['turnover']) }}</td></tr>
            @foreach ($report['expense_boxes'] as $box)
                <tr>
                    <td>{{ $box['full_form_box'] }}</td>
                    <td>{{ $box['short_form_box'] }}</td>
                    <td>
                        {{ $box['label'] }}
                        <div class="muted">
                            @foreach ($box['categories'] as $category){{ $loop->first ? '' : ' · ' }}{{ $category['label'] }} {{ $money($category['amount']) }}@endforeach
                        </div>
                    </td>
                    <td class="num">{{ $money($box['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="total"><td>31</td><td>20</td><td>Total allowable expenses</td><td class="num">{{ $money($report['total_expenses']) }}</td></tr>
            <tr class="total">
                <td>{{ $report['net_profit'] >= 0 ? 47 : 48 }}</td><td></td>
                <td>{{ $report['net_profit'] >= 0 ? 'Net profit' : 'Net loss' }}</td>
                <td class="num">{{ $money(abs($report['net_profit'])) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($report['vat'])
        <h2>VAT in this tax year</h2>
        <table>
            <tbody>
                <tr><td>VAT on sales</td><td class="num">{{ $money($report['vat']['output']) }}</td></tr>
                <tr><td>VAT on expenses</td><td class="num">{{ $money($report['vat']['input']) }}</td></tr>
                <tr class="total"><td>Net VAT</td><td class="num">{{ $money($report['vat']['net']) }}</td></tr>
            </tbody>
        </table>
    @endif

    <h2>By quarter</h2>
    <table>
        <thead><tr><th>Quarter</th><th class="num">Turnover</th><th class="num">Expenses</th><th class="num">Profit</th></tr></thead>
        <tbody>
            @foreach ($report['quarters'] as $quarter)
                <tr>
                    <td>{{ $quarter['label'] }}</td>
                    <td class="num">{{ $money($quarter['turnover']) }}</td>
                    <td class="num">{{ $money($quarter['expenses']) }}</td>
                    <td class="num">{{ $money($quarter['profit']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="note">
        A guide to help with your Self Assessment, not tax advice. These figures come from the invoices, payments and expenses
        recorded in Estymate. Check them and adjust for anything not recorded here, such as private use, cash takings or capital
        allowances, before you file.
        @unless ($report['tax_year']['in_progress'])
            The online return is due by {{ $date($report['tax_year']['filing_deadline']) }}.
        @endunless
        @if ($report['unpaid_expenses'])
            {{ $report['unpaid_expenses']['count'] }} expense(s) dated in this year have no "paid on" date and are left out on the cash basis.
        @endif
    </div>
</body>
</html>
