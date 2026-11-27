<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $job->number() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #1e293b; font-size: 11px; margin: 0; padding: 22px; }
        .header { border-bottom: 2px solid #0033a9; padding-bottom: 10px; margin-bottom: 12px; }
        .title { font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin-top: 6px; }
        .meta { font-size: 10px; color: #64748b; margin-top: 2px; }
        h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; margin: 14px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 5px 4px; border-bottom: 1px solid #e2e8f0; vertical-align: top; text-align: left; }
        th { font-size: 10px; color: #64748b; font-weight: normal; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; font-size: 13px; border-top: 2px solid #0033a9; border-bottom: none; }
        .muted { color: #64748b; }
        .sign { margin-top: 30px; }
        .sign td { border: none; padding-top: 24px; }
        .footer { margin-top: 18px; text-align: center; font-size: 9px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="header">
        @include('pdf.partials.branded-shop-name', ['shop' => $job->shop])
        <div class="title">{{ $estimate ? 'Estimate' : 'Job Card' }}</div>
        <div class="meta">{{ $job->number() }} &middot; received {{ $job->received_on->format('d M Y') }}{!! $job->promised_on ? ' &middot; promised '.e($job->promised_on->format('d M Y')) : '' !!} &middot; {{ $job->status->label() }}</div>
    </div>

    <table>
        <tr><td class="muted">Customer</td><td>{{ $job->customer?->name }}@if ($job->customer?->phone) &middot; {{ $job->customer->phone }}@endif</td></tr>
        @if ($job->technician)
            <tr><td class="muted">Technician</td><td>{{ $job->technician->name }}</td></tr>
        @endif
        @if ($job->site_visit)
            <tr><td class="muted">Site visit</td><td>{{ $job->site_address }}</td></tr>
        @endif
    </table>

    <h3>Brought in</h3>
    <table>
        @foreach ($job->items as $item)
            <tr>
                <td style="width: 35%"><strong>{{ $item->label }}</strong><br><span class="muted">{{ $item->kind->label() }}</span></td>
                <td>
                    @foreach ($item->kind->fields() as $key => $label)
                        @if (filled($item->details[$key] ?? null))
                            <span class="muted">{{ $label }}:</span> {{ $item->details[$key] }}<br>
                        @endif
                    @endforeach
                    @if ($item->problem)
                        <span class="muted">Problem:</span> {{ $item->problem }}<br>
                    @endif
                    @if ($item->findings)
                        <span class="muted">Found:</span> {{ $item->findings }}
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

    @if ($job->lines->isNotEmpty() || (float) $job->visit_charge > 0)
        <h3>Parts &amp; labour</h3>
        <table>
            <tr><th>Item</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr>
            @foreach ($job->lines as $line)
                <tr>
                    <td>{{ $line->description }} <span class="muted">({{ $line->kind->label() }})</span></td>
                    <td class="num">{{ \App\Support\Quantity::plain($line->quantity) }}</td>
                    <td class="num">{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="num">{{ number_format($line->total(), 2) }}</td>
                </tr>
            @endforeach
            @if ((float) $job->visit_charge > 0)
                <tr><td>Site visit</td><td class="num">1</td><td class="num">{{ number_format((float) $job->visit_charge, 2) }}</td><td class="num">{{ number_format((float) $job->visit_charge, 2) }}</td></tr>
            @endif
            <tr class="total"><td colspan="3">{{ $estimate ? 'Estimated total' : 'Total' }}</td><td class="num">Rs {{ number_format($job->total(), 2) }}</td></tr>
        </table>
    @endif

    @if ($deposits > 0)
        <p>Advance received: <strong>Rs {{ number_format($deposits, 2) }}</strong></p>
    @endif
    @if ($job->estimate_approved_at)
        <p class="muted">Estimate approved {{ $job->estimate_approved_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }} ({{ $job->estimate_approved_via }}).</p>
    @endif
    @if ($job->warranty_days)
        <p>Warranty on this work: <strong>{{ $job->warranty_days }} days</strong> {{ $job->warranty_until ? '(until '.$job->warranty_until->format('d M Y').')' : 'from delivery' }}.</p>
    @endif
    @if ($job->notes)
        <p class="muted">{{ $job->notes }}</p>
    @endif

    <table class="sign">
        <tr><td>Customer signature: ____________________</td><td class="num">For {{ $job->shop->invoiceDisplayName() }}: ____________________</td></tr>
    </table>

    <div class="footer">{{ config('branding.powered_by') }}</div>
</body>
</html>
