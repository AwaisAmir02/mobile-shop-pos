<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #151a20;
            font-size: 11px;
            margin: 0;
            padding: 28px 32px;
        }
        .wrap { word-wrap: break-word; overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        .header { border-bottom: 3px solid #0033a9; padding-bottom: 12px; }
        .logo { height: 44px; }
        .brand-meta { font-size: 9px; color: #64748b; text-align: right; }
        .title { font-size: 20px; font-weight: bold; color: #022157; letter-spacing: 1px; text-transform: uppercase; text-align: right; }
        .meta { font-size: 10px; color: #64748b; text-align: right; margin-top: 3px; }
        .section-label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #0033a9; margin-bottom: 4px; }
        .bill-to { margin-top: 18px; }
        .bill-to .shop-name { font-size: 14px; font-weight: bold; color: #022157; }
        .bill-to .detail { font-size: 10px; color: #475569; margin-top: 2px; }
        .period { margin-top: 18px; border: 1px solid #cbd5e1; border-radius: 4px; }
        .period td { padding: 8px 10px; }
        .period .label { font-size: 9px; color: #64748b; text-transform: uppercase; }
        .period .value { font-size: 11px; font-weight: bold; color: #151a20; }
        .items { margin-top: 18px; }
        .items th {
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            background: #f1f5f9;
            padding: 7px 9px;
            border-bottom: 1px solid #cbd5e1;
        }
        .items td { padding: 9px; border-bottom: 1px solid #e2e8f0; font-size: 11px; }
        .text-right { text-align: right; }
        .totals { margin-top: 10px; }
        .totals td { padding: 4px 9px; font-size: 11px; }
        .totals .grand td { font-size: 15px; font-weight: bold; color: #022157; border-top: 2px solid #0033a9; padding-top: 8px; }
        .stamp {
            display: inline-block;
            margin-top: 18px;
            padding: 6px 14px;
            border: 2px solid;
            border-radius: 4px;
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .stamp-paid { color: #059669; border-color: #059669; }
        .stamp-unpaid { color: #dc2626; border-color: #dc2626; }
        .stamp-cancelled { color: #dc2626; border-color: #dc2626; text-decoration: line-through; }
        .info-block { margin-top: 20px; font-size: 10px; color: #334155; }
        .info-block p { margin: 3px 0; }
        .notes { margin-top: 16px; padding: 10px; background: #f8fafc; border-left: 3px solid #0033a9; font-size: 10px; color: #334155; }
        .footer { margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 8px; text-align: center; font-size: 9px; color: #94a3b8; }
        .accent { color: #fe6402; font-weight: bold; }
        .cancelled-note { margin-top: 12px; font-size: 10px; color: #dc2626; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                @if ($logo)
                    <img class="logo" src="{{ $logo }}" alt="{{ config('branding.name') }}">
                @else
                    <span style="font-size: 16px; font-weight: bold; color: #022157;">{{ config('branding.name') }}</span>
                @endif
            </td>
            <td>
                <div class="title">Invoice</div>
                <div class="meta">{{ $invoice->invoice_number }}</div>
                <div class="meta">Issued {{ $invoice->created_at->format('d M Y') }}</div>
            </td>
        </tr>
        <tr>
            <td class="brand-meta" colspan="2" style="text-align: left; padding-top: 4px;">
                {{ config('branding.tagline') }} · <span class="accent">{{ config('branding.domain') }}</span>
            </td>
        </tr>
    </table>

    <table class="bill-to">
        <tr>
            <td style="width: 60%;">
                <div class="section-label">Bill To</div>
                <div class="wrap shop-name">{{ $shop->name }}</div>
                @if ($shop->address)
                    <div class="wrap detail">{{ $shop->address }}</div>
                @endif
                @if ($shop->phone)
                    <div class="wrap detail">Phone: {{ $shop->phone }}</div>
                @endif
                @if ($shop->email)
                    <div class="wrap detail">Email: {{ $shop->email }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="period">
        <tr>
            <td>
                <div class="label">Period From</div>
                <div class="value">{{ $invoice->period_start->format('d M Y') }}</div>
            </td>
            <td>
                <div class="label">Period To</div>
                <div class="value">{{ $invoice->period_end->format('d M Y') }}</div>
            </td>
            <td>
                <div class="label">Months Covered</div>
                <div class="value">{{ $invoice->months }}</div>
            </td>
            <td>
                <div class="label">Plan</div>
                <div class="value">{{ $invoice->planLabel() }}</div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="wrap">
                    {{ config('branding.name') }} subscription — {{ $invoice->planLabel() }} plan, {{ $invoice->months }} {{ \Illuminate\Support\Str::plural('month', $invoice->months) }}
                    ({{ $invoice->period_start->format('d M Y') }} – {{ $invoice->period_end->format('d M Y') }})
                </td>
                <td class="text-right">Rs {{ number_format($invoice->amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td style="width: 55%;"></td>
            <td>
                <table>
                    <tr>
                        <td>Subtotal</td>
                        <td class="text-right">Rs {{ number_format($invoice->amount, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Discount</td>
                        <td class="text-right">− Rs {{ number_format($invoice->discount, 2) }}</td>
                    </tr>
                    <tr class="grand">
                        <td>Total</td>
                        <td class="text-right">Rs {{ number_format($invoice->total, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($invoice->status === \App\Enums\SubscriptionInvoiceStatus::Paid)
        <div class="stamp stamp-paid">PAID{{ $invoice->paid_at ? ' · '.$invoice->paid_at->format('d M Y') : '' }}</div>
    @elseif ($invoice->status === \App\Enums\SubscriptionInvoiceStatus::Cancelled)
        <div class="stamp stamp-cancelled">CANCELLED</div>
        <div class="cancelled-note wrap">This invoice was cancelled{{ $invoice->cancelled_at ? ' on '.$invoice->cancelled_at->format('d M Y') : '' }}.@if ($invoice->cancel_reason) Reason: {{ $invoice->cancel_reason }}@endif</div>
    @else
        <div class="stamp stamp-unpaid">UNPAID</div>
    @endif

    @if ($invoice->notes)
        <div class="notes wrap">
            <div class="section-label">Notes</div>
            {{ $invoice->notes }}
        </div>
    @endif

    @if ($paymentInstructions || $supportContact)
        <div class="info-block">
            @if ($paymentInstructions)
                <div class="section-label">Payment Instructions</div>
                <p class="wrap">{{ $paymentInstructions }}</p>
            @endif
            @if ($supportContact)
                <div class="section-label" style="margin-top: 8px;">Support</div>
                <p class="wrap">{{ $supportContact }}</p>
            @endif
        </div>
    @endif

    <div class="footer">
        {{ config('branding.name') }} · {{ config('branding.domain') }} · {{ config('branding.tagline') }}
    </div>
</body>
</html>
