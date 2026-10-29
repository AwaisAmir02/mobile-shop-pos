<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; text-align: center; }
        .shop { font-size: 26px; font-weight: bold; margin-top: 10px; }
        .headline { font-size: 22px; font-weight: bold; color: #0f766e; margin-top: 18px; }
        .sub { font-size: 13px; color: #475569; margin-top: 6px; }
        .qr { margin-top: 22px; }
        .qr img { width: 300px; height: 300px; }
        .steps { font-size: 12px; color: #334155; margin-top: 16px; line-height: 1.7; }
        .number { font-size: 16px; font-weight: bold; margin-top: 14px; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9px; color: #94a3b8; }
    </style>
</head>
<body>
    @if ($shop->branding_enabled && $shop->logoDataUri())
        <img src="{{ $shop->logoDataUri() }}" style="max-height: 60px; max-width: 200px;">
    @endif
    <div class="shop">{{ $shop->invoiceDisplayName() }}</div>
    <div class="headline">{{ $isCatalog ? 'Scan to see our products on WhatsApp' : 'Scan to chat with us on WhatsApp' }}</div>
    <div class="sub">{{ $isCatalog ? 'WhatsApp par hamari products dekhne ke liye scan karein' : 'WhatsApp par baat karne ke liye scan karein' }}</div>
    <div class="qr"><img src="{{ $qrImage }}"></div>
    <div class="steps">
        1. Open your phone camera &nbsp;·&nbsp; 2. Point at the code &nbsp;·&nbsp; 3. Tap the WhatsApp link<br>
        Browse, order and get your invoice — all on WhatsApp.
    </div>
    @if ($number)
        <div class="number">{{ $number }}</div>
    @endif
    <div class="footer">{{ config('branding.powered_by') }}</div>
</body>
</html>
