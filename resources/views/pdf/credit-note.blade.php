<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $return->number() }}</title>
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
        .item-note {
            font-size: 8px;
            color: #64748b;
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
    @if ($return->isCancelled())
        <div class="watermark">CANCELLED</div>
    @endif
    <table class="layout header">
        <tr>
            <td>
                @include('pdf.partials.branded-shop-name', ['shop' => $return->sale->shop])
                @if ($return->sale->shop->phone)
                    <div class="shop-meta">{{ $return->sale->shop->phone }}</div>
                @endif
            </td>
            <td class="text-right">
                <div class="invoice-title">Credit Note</div>
                <div class="invoice-meta">{{ $return->number() }}</div>
                <div class="invoice-meta">{{ $return->return_date->format('d M Y') }}</div>
                <div class="invoice-meta">Against {{ $return->sale->invoiceNumber() }} of {{ $return->sale->created_at->format('d M Y') }}</div>
                @if ($return->sale->customer)
                    <div class="invoice-meta">{{ $return->sale->customer->name }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Item returned</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Value</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($return->items as $item)
                <tr>
                    <td>
                        {{ $item->product_name }}
                        @if ($item->serial_number)
                            <div class="item-note">IMEI {{ $item->serial_number }}</div>
                        @endif
                        @if ($item->disposition === 'damaged')
                            <div class="item-note">Damaged</div>
                        @endif
                    </td>
                    <td class="text-right">{{ $item->unit->amount($item->quantity) }}</td>
                    <td class="text-right">Rs {{ number_format($item->value, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr class="total-row">
            <td>Total returned</td>
            <td class="text-right">Rs {{ number_format($return->value_total, 2) }}</td>
        </tr>
        @if ((float) $return->due_reduction > 0)
            <tr>
                <td>Taken off amount owed</td>
                <td class="text-right">Rs {{ number_format($return->due_reduction, 2) }}</td>
            </tr>
        @endif
        @if ($return->exchange_sale_id)
            <tr>
                <td>Toward exchange INV-{{ str_pad((string) $return->exchange_sale_id, 6, '0', STR_PAD_LEFT) }}</td>
                <td class="text-right">Rs {{ number_format($return->refund_exchange, 2) }}</td>
            </tr>
        @endif
        @if ((float) $return->refund_credit > 0)
            <tr>
                <td>Given as store credit{{ $return->customer ? ' to '.$return->customer->name : '' }}</td>
                <td class="text-right">Rs {{ number_format($return->refund_credit, 2) }}</td>
            </tr>
        @endif
        @if ((float) $return->refund_cash > 0)
            <tr>
                <td>Refunded ({{ $return->refundSourceLabel() }})</td>
                <td class="text-right">Rs {{ number_format($return->refund_cash, 2) }}</td>
            </tr>
        @endif
    </table>

    @if ($return->reason)
        <p class="invoice-meta">Reason: {{ $return->reason }}</p>
    @endif

    <div class="footer">
        @if ($return->user)
            Processed by {{ $return->user->name }}
        @endif
        <div class="powered-by">{{ config('branding.powered_by') }}</div>
    </div>
</body>
</html>
