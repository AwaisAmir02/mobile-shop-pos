<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $order->number() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #1e293b; font-size: 12px; margin: 0; padding: 24px; }
        .header { width: 100%; border-bottom: 2px solid #0033a9; padding-bottom: 12px; margin-bottom: 16px; }
        .shop-name { font-size: 20px; font-weight: bold; color: #022157; }
        .shop-meta { font-size: 10px; color: #64748b; margin-top: 2px; }
        .invoice-title { font-size: 16px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .invoice-meta { font-size: 10px; color: #64748b; margin-top: 4px; }
        table.layout { width: 100%; }
        table.layout td { vertical-align: top; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.items th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; background: #f1f5f9; padding: 6px 8px; border-bottom: 1px solid #cbd5e1; }
        table.items td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; font-size: 11px; }
        .text-right { text-align: right; }
        .totals { width: 55%; margin-left: 45%; margin-top: 10px; }
        .totals td { padding: 4px 8px; font-size: 11px; }
        .totals .total-row td { font-size: 15px; font-weight: bold; border-top: 2px solid #0033a9; padding-top: 8px; }
        .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; }
        .footer { margin-top: 32px; text-align: center; font-size: 10px; color: #94a3b8; }
        .powered-by { margin-top: 4px; font-size: 9px; color: #cbd5e1; }
        .watermark { position: fixed; top: 260px; left: 60px; font-size: 64px; font-weight: bold; color: #ef4444; opacity: 0.25; transform: rotate(-25deg); z-index: 100; }
    </style>
</head>
<body>
    @if ($order->isCancelled())
        <div class="watermark">CANCELLED</div>
    @endif
    <table class="layout header">
        <tr>
            <td>
                @include('pdf.partials.branded-shop-name', ['shop' => $order->shop])
                @if ($order->shop->phone)
                    <div class="shop-meta">{{ $order->shop->phone }}</div>
                @endif
            </td>
            <td class="text-right">
                <div class="invoice-title">Purchase Order</div>
                <div class="invoice-meta">{{ $order->number() }}</div>
                <div class="invoice-meta">{{ $order->order_date->format('d M Y') }}</div>
                @if ($order->expected_date)
                    <div class="invoice-meta">Expected {{ $order->expected_date->format('d M Y') }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="label">To</div>
    <div>{{ $order->vendor?->company_name ?: $order->vendor?->name }}</div>
    @if ($order->vendor?->phone)
        <div class="shop-meta">{{ $order->vendor->phone }}</div>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th>Item</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Expected Cost</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->lines as $line)
                <tr>
                    <td>{{ $line->product_name }}</td>
                    <td class="text-right">{{ $line->unit->groupedAmount($line->quantity) }}</td>
                    <td class="text-right">{{ $line->expected_cost !== null ? 'Rs '.number_format($line->expected_cost, 2) : '—' }}</td>
                    <td class="text-right">{{ $line->expected_cost !== null ? 'Rs '.number_format(\App\Support\Quantity::money($line->expected_cost, $line->quantity), 2) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr class="total-row">
            <td>Total</td>
            <td class="text-right">Rs {{ number_format($order->orderedAmount(), 2) }}</td>
        </tr>
    </table>

    @if ($order->note)
        <p style="margin-top: 16px; font-size: 11px;">{{ $order->note }}</p>
    @endif

    <div class="footer">
        <div class="powered-by">{{ config('branding.powered_by') }}</div>
    </div>
</body>
</html>
