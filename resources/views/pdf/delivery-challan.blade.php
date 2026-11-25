<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $order->number() }}</title>
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
    @php($delivered = $order->sale !== null)
    <table class="layout header">
        <tr>
            <td>
                @include('pdf.partials.branded-shop-name', ['shop' => $shop])
                @if ($shop->phone)<div class="shop-meta">{{ $shop->phone }}</div>@endif
            </td>
            <td class="text-right">
                <div class="title">Delivery Challan</div>
                <div class="meta">{{ $order->number() }}{{ $delivered ? ' · '.$order->sale->invoiceNumber() : '' }}</div>
                <div class="meta">{{ ($order->delivered_at ?? now())->format('d M Y') }}</div>
            </td>
        </tr>
    </table>
    <table class="layout">
        <tr>
            <td>
                <div class="label">Deliver to</div>
                <div class="strong">{{ $order->customer?->business_name ?: $order->customer?->name }}</div>
                <div class="meta">{{ collect([$order->customer?->contact_person ?: ($order->customer?->business_name ? $order->customer?->name : null), $order->customer?->phone, $order->customer?->address, $order->customer?->area])->filter()->implode(' · ') }}</div>
            </td>
            <td class="text-right">
                @if ($order->loadSheet)
                    <div class="label">Van</div>
                    <div class="meta">{{ collect([$order->loadSheet->number(), $order->loadSheet->vehicle, $order->loadSheet->driver])->filter()->implode(' · ') }}</div>
                @endif
                @if ($order->booker)
                    <div class="meta">Booked by {{ $order->booker->name }}</div>
                @endif
            </td>
        </tr>
    </table>
    <table class="items">
        <thead><tr><th>Item</th><th class="text-right">Quantity</th></tr></thead>
        <tbody>
            @if ($delivered)
                @foreach ($order->sale->items->groupBy('product_id') as $items)
                    <tr><td>{{ $items->first()->product_name }}</td><td class="text-right">{{ \App\Support\Quantity::grouped(\App\Support\Quantity::sum($items->pluck('quantity'))) }}</td></tr>
                @endforeach
            @else
                @foreach ($order->lines as $line)
                    <tr><td>{{ $line->product_name }}</td><td class="text-right">{{ $line->quantityLabel() }}</td></tr>
                @endforeach
            @endif
        </tbody>
    </table>
    <p class="meta">Goods received in good condition. The bill follows the delivered quantities.</p>
    <table class="signatures"><tr><td>Delivered by</td><td>Received by (name, stamp)</td></tr></table>
    <div class="footer">{{ config('branding.powered_by') }}</div>
</body>
</html>
