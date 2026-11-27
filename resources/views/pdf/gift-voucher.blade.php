<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Gift voucher {{ $voucher->code }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #1e293b; margin: 0; padding: 24px; text-align: center; }
        .card { border: 2px solid #0033a9; border-radius: 12px; padding: 22px; }
        .title { font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 3px; color: #64748b; margin-top: 10px; }
        .amount { font-size: 34px; font-weight: bold; color: #022157; margin: 10px 0; }
        .code { font-size: 20px; letter-spacing: 4px; font-family: 'Courier', monospace; border: 1px dashed #94a3b8; display: inline-block; padding: 6px 14px; margin: 6px 0; }
        .muted { color: #64748b; font-size: 11px; margin-top: 6px; }
        .footer { margin-top: 14px; font-size: 9px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        @include('pdf.partials.branded-shop-name', ['shop' => $voucher->shop])
        <div class="title">Gift voucher</div>
        @if ($voucher->recipient_name)
            <div class="muted">For {{ $voucher->recipient_name }}</div>
        @endif
        <div class="amount">Rs {{ number_format((float) $voucher->amount, 0) }}</div>
        <div class="code">{{ $voucher->code }}</div>
        <div class="muted">Show this code when you pay.{{ $voucher->expires_on ? ' Valid until '.$voucher->expires_on->format('d M Y').'.' : '' }}</div>
        @if ($voucher->buyer_name || $voucher->buyer)
            <div class="muted">From {{ $voucher->buyer_name ?? $voucher->buyer?->name }}</div>
        @endif
    </div>
    <div class="footer">{{ config('branding.powered_by') }}</div>
</body>
</html>
