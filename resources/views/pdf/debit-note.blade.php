<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $return->number() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #1e293b; font-size: 11px; margin: 0; padding: 20px; }
        .header { border-bottom: 2px solid #0033a9; padding-bottom: 10px; margin-bottom: 12px; }
        .shop-name { font-size: 16px; font-weight: bold; color: #022157; }
        .shop-meta { font-size: 10px; color: #64748b; margin-top: 2px; }
        .title { font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin-top: 6px; }
        .meta { font-size: 10px; color: #64748b; margin-top: 2px; }
        table.layout { width: 100%; }
        table.layout td { vertical-align: top; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.items th { text-align: left; font-size: 9px; text-transform: uppercase; color: #64748b; background: #f1f5f9; padding: 5px 6px; border-bottom: 1px solid #cbd5e1; }
        table.items td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; font-size: 10px; vertical-align: top; }
        .text-right { text-align: right; }
        .total-row td { font-weight: bold; border-top: 2px solid #0033a9; }
        .label { font-size: 9px; text-transform: uppercase; color: #64748b; }
        .strong { font-weight: bold; color: #022157; }
        .signatures { width: 100%; margin-top: 36px; }
        .signatures td { width: 50%; padding-top: 24px; border-top: 1px solid #94a3b8; font-size: 9px; color: #64748b; text-align: center; }
        .footer { margin-top: 20px; text-align: center; font-size: 9px; color: #94a3b8; }
    </style>
</head>
<body>
    <table class="layout header">
        <tr>
            <td>
                @include('pdf.partials.branded-shop-name', ['shop' => $shop])
                @if ($shop->phone)<div class="shop-meta">{{ $shop->phone }}</div>@endif
            </td>
            <td class="text-right">
                <div class="title">Debit Note</div>
                <div class="meta">{{ $return->number() }} · {{ $return->return_date->format('d M Y') }}</div>
                @if ($return->isCancelled())<div class="meta">CANCELLED</div>@endif
            </td>
        </tr>
    </table>
    <div class="label">To</div>
    <div class="strong">{{ $return->vendor?->company_name ?: $return->vendor?->name }}</div>
    <div class="meta">{{ collect([$return->vendor?->company_name ? $return->vendor?->name : null, $return->vendor?->phone, $return->vendor?->address])->filter()->implode(' · ') }}</div>
    <p class="meta">Goods returned: {{ $return->reasonLabel() }}{{ $return->note ? ' · '.$return->note : '' }}. Please credit our account with the amount below.</p>
    <table class="items">
        <thead><tr><th>Item</th><th class="text-right">Quantity</th><th class="text-right">Rate</th><th class="text-right">Amount</th></tr></thead>
        <tbody>
            @foreach ($return->items as $item)
                <tr>
                    <td>{{ $item->product_name }}@if ($item->stockIn?->batch_number)<div class="meta">Batch {{ $item->stockIn->batch_number }}</div>@endif @if ($item->stockIn?->expiry_date)<div class="meta">Expiry {{ $item->stockIn->expiry_date->format('d M Y') }}</div>@endif</td>
                    <td class="text-right">{{ \App\Support\Quantity::grouped($item->quantity) }}</td>
                    <td class="text-right">Rs {{ number_format($item->cost_price, 2) }}</td>
                    <td class="text-right">Rs {{ number_format($item->line_value, 2) }}</td>
                </tr>
            @endforeach
            <tr class="total-row"><td colspan="3">Total</td><td class="text-right">Rs {{ number_format($return->total_value, 2) }}</td></tr>
        </tbody>
    </table>
    @if ($credit && ! $return->isCancelled())
        <p class="meta">Taken off what we owe you: Rs {{ number_format((float) $credit->amount - (float) $credit->remaining - (float) $credit->refunds()->sum('amount'), 2) }}{{ (float) $credit->remaining > 0 ? ' · still due to us: Rs '.number_format((float) $credit->remaining, 2) : '' }}</p>
    @endif
    <table class="signatures"><tr><td>Sent by{{ $return->user ? ': '.$return->user->name : '' }}</td><td>Received by (vendor)</td></tr></table>
    <div class="footer">{{ config('branding.powered_by') }}</div>
</body>
</html>
