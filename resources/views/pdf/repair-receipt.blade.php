<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $repair->receiptNumber() }}</title>
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
            border-bottom: 2px solid #049669;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .shop-name {
            font-size: 16px;
            font-weight: bold;
            color: #037a56;
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
            vertical-align: top;
        }
        table.details td.label {
            color: #64748b;
            white-space: nowrap;
            padding-right: 12px;
        }
        table.details td.value {
            text-align: right;
            font-weight: bold;
        }
        .amount-row td {
            font-size: 18px;
            border-bottom: none;
            border-top: 2px solid #049669;
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
        <div class="shop-name">{{ $repair->shop->name }}</div>
        <div class="receipt-title">Repair Job Ticket</div>
        <div class="receipt-meta">{{ $repair->receiptNumber() }} &middot; {{ $repair->created_at->format('d M Y, h:i A') }}</div>
    </div>

    <table class="details">
        <tr>
            <td class="label">Category</td>
            <td class="value">{{ $repair->category->label() }}</td>
        </tr>
        <tr>
            <td class="label">Description</td>
            <td class="value">{{ $repair->description }}</td>
        </tr>
        <tr>
            <td class="label">Customer</td>
            <td class="value">{{ $repair->customer?->name ?? 'Walk-in' }}</td>
        </tr>
        <tr>
            <td class="label">Amount</td>
            <td class="value">Rs {{ number_format($repair->amount, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Discount</td>
            <td class="value">− Rs {{ number_format($repair->discount, 2) }}</td>
        </tr>
        <tr class="amount-row">
            <td class="label">Total</td>
            <td class="value">Rs {{ number_format($repair->total, 2) }}</td>
        </tr>
    </table>

    <div class="footer">
        Thank you for your business!
        @if ($repair->user)
            &middot; Served by {{ $repair->user->name }}
        @endif
    </div>
</body>
</html>
