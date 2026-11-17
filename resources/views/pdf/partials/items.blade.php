{{-- The line items. Layouts style the table through the .items class. --}}
<table class="items">
    <thead>
        <tr>
            <th>Description</th>
            <th class="num">Qty</th>
            <th class="num">Unit price</th>
            <th class="num">VAT</th>
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($document->items as $item)
            <tr class="{{ $loop->even ? 'even' : 'odd' }}">
                <td style="white-space: pre-line">{{ $item->description }}</td>
                <td class="num">{{ $number($item->quantity) }}{{ $item->unit ? ' '.$item->unit : '' }}</td>
                <td class="num">{{ $money($item->unit_price) }}</td>
                <td class="num">{{ $number($item->tax_rate) }}%</td>
                <td class="num">{{ $money($item->net_amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
