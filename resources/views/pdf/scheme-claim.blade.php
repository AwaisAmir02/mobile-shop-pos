<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $claim->number() }}</title>
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
                <div class="title">Scheme Claim</div>
                <div class="meta">{{ $claim->number() }} · {{ $claim->claimed_on->format('d M Y') }}</div>
            </td>
        </tr>
    </table>
    <div class="label">To</div>
    <div class="strong">{{ $claim->vendor?->company_name ?: $claim->vendor?->name }}</div>
    <p class="meta">Free goods given to our customers under your trade schemes, invoice by invoice, at cost. {{ $claim->note }}</p>
    <table class="items">
        <thead><tr><th>Date</th><th>Invoice</th><th>Customer</th><th>Scheme</th><th class="text-right">Free qty</th><th class="text-right">Value</th></tr></thead>
        <tbody>
            @foreach ($claim->redemptions as $redemption)
                <tr>
                    <td>{{ $redemption->sale?->created_at->format('d M Y') }}</td>
                    <td>{{ $redemption->sale?->invoiceNumber() }}</td>
                    <td>{{ $redemption->sale?->customer?->displayName() ?? 'Walk-in' }}</td>
                    <td>{{ $redemption->scheme?->name }} ({{ $redemption->scheme?->offerLabel() }})</td>
                    <td class="text-right">{{ \App\Support\Quantity::grouped($redemption->free_quantity) }}</td>
                    <td class="text-right">Rs {{ number_format($redemption->cost_value, 2) }}</td>
                </tr>
            @endforeach
            <tr class="total-row"><td colspan="5">Total claimed</td><td class="text-right">Rs {{ number_format($claim->claimed_amount, 2) }}</td></tr>
        </tbody>
    </table>
    <table class="signatures"><tr><td>Claimed by</td><td>Approved by (company)</td></tr></table>
    <div class="footer">{{ config('branding.powered_by') }}</div>
</body>
</html>
