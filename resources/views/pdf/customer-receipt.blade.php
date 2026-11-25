<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $receipt->number() }}</title>
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
            border-bottom: 2px solid #0033a9;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .shop-name {
            font-size: 16px;
            font-weight: bold;
            color: #022157;
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
            border-top: 2px solid #0033a9;
            padding-top: 12px;
        }
        .footer {
            margin-top: 24px;
            text-align: center;
            font-size: 10px;
            color: #94a3b8;
        }
        .powered-by {
            margin-top: 4px;
            font-size: 9px;
            color: #cbd5e1;
        }
    </style>
</head>
<body>
    <div class="header">
        @include('pdf.partials.branded-shop-name', ['shop' => $receipt->customer->shop])
        <div class="receipt-title">Payment Receipt</div>
        <div class="receipt-meta">{{ $receipt->number() }} &middot; {{ $receipt->receipt_date->format('d M Y') }}</div>
    </div>

    <table class="details">
        <tr>
            <td class="label">Received from</td>
            <td class="value">{{ $receipt->customer->displayName() }}</td>
        </tr>
        <tr>
            <td class="label">Received into</td>
            <td class="value">{{ $receipt->receivedIntoLabel() }}</td>
        </tr>
        @if ($receipt->note)
            <tr>
                <td class="label">Note</td>
                <td class="value">{{ $receipt->note }}</td>
            </tr>
        @endif
        @foreach ($receipt->applied_to as $part)
            <tr>
                <td class="label">{{ $part['source'] === 'sale' ? 'Bill '.$part['reference'] : $part['label'] }}</td>
                <td class="value">Rs {{ number_format($part['amount'], 2) }}</td>
            </tr>
        @endforeach
        <tr class="amount-row">
            <td class="label">Total received</td>
            <td class="value">Rs {{ number_format($receipt->amount, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Balance now</td>
            <td class="value">Rs {{ number_format($balanceNow, 2) }}</td>
        </tr>
    </table>

    <div class="footer">
        Thank you!
        @if ($receipt->user)
            &middot; Received by {{ $receipt->user->name }}
        @endif
        <div class="powered-by">{{ config('branding.powered_by') }}</div>
    </div>
</body>
</html>
