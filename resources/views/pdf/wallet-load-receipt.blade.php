<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $load->receiptNumber() }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #1e293b;
            font-size: 12px;
            margin: 0;
            padding: 20px;
        }
        .header {
            border-bottom: 2px solid #0c8f76;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .shop-name {
            font-size: 16px;
            font-weight: bold;
            color: #0c7260;
        }
        .receipt-title {
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 6px;
        }
        .receipt-meta {
            font-size: 10px;
            color: #64748b;
            margin-top: 2px;
        }
        table.details {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        table.details td {
            padding: 7px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 11px;
        }
        table.details td.label {
            color: #64748b;
        }
        table.details td.value {
            text-align: right;
            font-weight: bold;
        }
        .amount-row td {
            font-size: 18px;
            border-bottom: none;
            border-top: 2px solid #0c8f76;
            padding-top: 12px;
        }
        .footer {
            margin-top: 24px;
            text-align: center;
            font-size: 10px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="shop-name">{{ $load->shop->name }}</div>
        <div class="receipt-title">Wallet Load Receipt</div>
        <div class="receipt-meta">{{ $load->receiptNumber() }} &middot; {{ $load->created_at->format('d M Y, h:i A') }}</div>
    </div>

    <table class="details">
        <tr>
            <td class="label">Provider</td>
            <td class="value">{{ $load->provider }}</td>
        </tr>
        <tr>
            <td class="label">Account Number</td>
            <td class="value">{{ $load->account_number }}</td>
        </tr>
        <tr class="amount-row">
            <td class="label">Amount</td>
            <td class="value">Rs {{ number_format($load->amount, 2) }}</td>
        </tr>
    </table>

    <div class="footer">
        Thank you for your business!
        @if ($load->user)
            &middot; Served by {{ $load->user->name }}
        @endif
    </div>
</body>
</html>
