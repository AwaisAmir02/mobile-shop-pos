<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $payslip->number() }}</title>
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
        .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; }
        .total td { font-size: 15px; font-weight: bold; border-top: 2px solid #0033a9; border-bottom: 0; padding-top: 8px; }
        .footer { margin-top: 32px; text-align: center; font-size: 10px; color: #94a3b8; }
        .powered-by { margin-top: 4px; font-size: 9px; color: #cbd5e1; }
        .watermark { position: fixed; top: 260px; left: 60px; font-size: 64px; font-weight: bold; color: #ef4444; opacity: 0.25; transform: rotate(-25deg); z-index: 100; }
    </style>
</head>
<body>
    @if ($payslip->isCancelled())
        <div class="watermark">CANCELLED</div>
    @elseif ($payslip->isDraft())
        <div class="watermark">DRAFT</div>
    @endif
    <table class="layout header">
        <tr>
            <td>
                @include('pdf.partials.branded-shop-name', ['shop' => $payslip->shop])
                @if ($payslip->shop->phone)
                    <div class="shop-meta">{{ $payslip->shop->phone }}</div>
                @endif
            </td>
            <td class="text-right">
                <div class="invoice-title">Payslip</div>
                <div class="invoice-meta">{{ $payslip->number() }}</div>
                <div class="invoice-meta">{{ $payslip->period->format('F Y') }}</div>
                @if ($payslip->paid_on)
                    <div class="invoice-meta">Paid {{ $payslip->paid_on->format('d M Y') }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="label">Employee</div>
    <div>{{ $payslip->employee_name }}</div>
    @if ($payslip->designation)
        <div class="shop-meta">{{ $payslip->designation }}</div>
    @endif

    <table class="items">
        <thead>
            <tr><th>Earnings</th><th class="text-right">Amount</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $payslip->salary_type->label() }}@if ($payslip->days !== null) ({{ rtrim(rtrim((string) $payslip->days, '0'), '.') }} days at Rs {{ number_format($payslip->rate, 2) }})@endif</td>
                <td class="text-right">Rs {{ number_format($payslip->base_pay, 2) }}</td>
            </tr>
            @foreach ($payslip->lines->where('kind', 'earning') as $line)
                <tr><td>{{ $line->label }}</td><td class="text-right">Rs {{ number_format($line->amount, 2) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    @if ($payslip->lines->where('kind', 'deduction')->isNotEmpty() || (float) $payslip->advance_recovery > 0)
        <table class="items">
            <thead>
                <tr><th>Deductions</th><th class="text-right">Amount</th></tr>
            </thead>
            <tbody>
                @foreach ($payslip->lines->where('kind', 'deduction') as $line)
                    <tr><td>{{ $line->label }}</td><td class="text-right">Rs {{ number_format($line->amount, 2) }}</td></tr>
                @endforeach
                @if ((float) $payslip->advance_recovery > 0)
                    <tr><td>Advance recovered</td><td class="text-right">Rs {{ number_format($payslip->advance_recovery, 2) }}</td></tr>
                @endif
            </tbody>
        </table>
    @endif

    <table class="items">
        <tr class="total"><td>Net Pay</td><td class="text-right">Rs {{ number_format($payslip->net_pay, 2) }}</td></tr>
    </table>

    <div class="footer">
        <div class="powered-by">{{ config('branding.powered_by') }}</div>
    </div>
</body>
</html>
