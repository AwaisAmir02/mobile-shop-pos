<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $sheet->number() }}</title>
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
            <td>@include('pdf.partials.branded-shop-name', ['shop' => $shop])</td>
            <td class="text-right">
                <div class="title">Load Sheet</div>
                <div class="meta">{{ $sheet->number() }} · {{ $sheet->load_date->format('d M Y') }}</div>
                <div class="meta">{{ collect([$sheet->route?->name, $sheet->vehicle, $sheet->driver, $sheet->salesman?->name])->filter()->implode(' · ') }}</div>
            </td>
        </tr>
    </table>
    <div class="label">Load</div>
    <table class="items">
        <thead><tr><th>Product</th><th class="text-right">Pieces</th><th class="text-right">In packs</th></tr></thead>
        <tbody>
            @foreach ($loading as $row)
                <tr>
                    <td>{{ $row['product'] }}</td>
                    <td class="text-right">{{ \App\Support\Quantity::grouped($row['quantity']) }}</td>
                    <td class="text-right">{{ $row['pack_size'] > 1 && $row['pack_name'] ? intdiv((int) $row['quantity'], (int) $row['pack_size']).' '.mb_strtolower($row['pack_name']).' + '.((int) $row['quantity'] % (int) $row['pack_size']) : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="label" style="margin-top: 14px">Stops</div>
    <table class="items">
        <thead><tr><th>#</th><th>Customer</th><th>Order</th><th class="text-right">Order value</th><th class="text-right">Owes now</th><th>Collected</th></tr></thead>
        <tbody>
            @foreach ($stops as $i => $order)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $order->customer?->displayName() }}<div class="meta">{{ collect([$order->customer?->area, $order->customer?->phone])->filter()->implode(' · ') }}</div></td>
                    <td>{{ $order->number() }}</td>
                    <td class="text-right">Rs {{ number_format($order->estimated_total, 2) }}</td>
                    <td class="text-right">Rs {{ number_format($owed[$order->id] ?? 0, 2) }}</td>
                    <td>&nbsp;</td>
                </tr>
            @endforeach
            <tr class="total-row"><td colspan="3">Total</td><td class="text-right">Rs {{ number_format($stops->sum('estimated_total'), 2) }}</td><td></td><td></td></tr>
        </tbody>
    </table>
    <table class="signatures"><tr><td>Loaded by</td><td>Salesman</td></tr></table>
    <div class="footer">{{ config('branding.powered_by') }}</div>
</body>
</html>
