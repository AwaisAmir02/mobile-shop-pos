<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $sale->invoiceNumber() }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #1e293b;
            font-size: 12px;
            margin: 0;
            padding: 24px;
        }
        .header {
            width: 100%;
            border-bottom: 2px solid #0033a9;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .shop-name {
            font-size: 20px;
            font-weight: bold;
            color: #022157;
        }
        .shop-meta {
            font-size: 10px;
            color: #64748b;
            margin-top: 2px;
        }
        .invoice-title {
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .invoice-meta {
            font-size: 10px;
            color: #64748b;
            margin-top: 4px;
        }
        table.layout { width: 100%; }
        table.layout td { vertical-align: top; }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        table.items th {
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            background: #f1f5f9;
            padding: 6px 8px;
            border-bottom: 1px solid #cbd5e1;
        }
        table.items td {
            padding: 6px 8px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 11px;
        }
        .text-right { text-align: right; }
        .totals {
            width: 55%;
            margin-left: 45%;
            margin-top: 10px;
        }
        .totals td {
            padding: 4px 8px;
            font-size: 11px;
        }
        .totals .total-row td {
            font-size: 15px;
            font-weight: bold;
            border-top: 2px solid #0033a9;
            padding-top: 8px;
        }
        .footer {
            margin-top: 32px;
            text-align: center;
            font-size: 10px;
            color: #94a3b8;
        }
        .powered-by {
            margin-top: 4px;
            font-size: 9px;
            color: #cbd5e1;
        }
        .overridden-hint {
            display: block;
            font-size: 8px;
            color: #d65002;
        }
        .watermark {
            position: fixed;
            top: 260px;
            left: 60px;
            font-size: 64px;
            font-weight: bold;
            color: #ef4444;
            opacity: 0.25;
            transform: rotate(-25deg);
            z-index: 100;
        }
    </style>
</head>
<body>
    @if ($sale->isCancelled())
        <div class="watermark">CANCELLED</div>
    @endif
    <table class="layout header">
        <tr>
            <td>
                @include('pdf.partials.branded-shop-name', ['shop' => $sale->shop])
                @if ($sale->shop->phone || $sale->shop->address)
                    <div class="shop-meta">
                        {{ collect([$sale->shop->address, $sale->shop->phone])->filter()->implode(' · ') }}
                    </div>
                @endif
            </td>
            <td class="text-right">
                <div class="invoice-title">Invoice</div>
                <div class="invoice-meta">{{ $sale->invoiceNumber() }}</div>
                <div class="invoice-meta">{{ $sale->created_at->format('d M Y, h:i A') }}</div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Item</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Unit Price</th>
                <th class="text-right">Discount</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sale->items as $item)
                <tr>
                    <td>{{ $item->product_name }}</td>
                    <td class="text-right">{{ $item->quantity }}</td>
                    <td class="text-right">
                        Rs {{ number_format($item->unit_price, 2) }}
                        @if ($item->wasPriceOverridden())
                            <span class="overridden-hint">overridden</span>
                        @endif
                    </td>
                    <td class="text-right">{{ $item->discount_amount > 0 ? 'Rs '.number_format($item->discount_amount, 2) : '—' }}</td>
                    <td class="text-right">Rs {{ number_format($item->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">Rs {{ number_format($sale->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>Discount</td>
            <td class="text-right">Rs {{ number_format($sale->discount_amount, 2) }}</td>
        </tr>
        <tr class="total-row">
            <td>Total</td>
            <td class="text-right">Rs {{ number_format($sale->total, 2) }}</td>
        </tr>
        <tr>
            <td>Paid</td>
            <td class="text-right">Rs {{ number_format($sale->amountPaid(), 2) }}</td>
        </tr>
        @if ($sale->amountDue() > 0)
            <tr>
                <td>Due</td>
                <td class="text-right">Rs {{ number_format($sale->amountDue(), 2) }}</td>
            </tr>
        @endif
    </table>

    <div class="footer">
        Thank you for your business!
        @if ($sale->user)
            &middot; Served by {{ $sale->user->name }}
        @endif
        <div class="powered-by">{{ config('branding.powered_by') }}</div>
    </div>
</body>
</html>
