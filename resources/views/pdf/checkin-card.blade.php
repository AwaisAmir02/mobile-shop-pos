<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #1e293b; margin: 0; padding: 10px; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: middle; }
        .title { font-size: 8px; text-transform: uppercase; letter-spacing: 2px; color: #64748b; margin-top: 4px; }
        .name { font-size: 14px; font-weight: bold; color: #022157; margin-top: 6px; }
        .number { font-size: 10px; color: #64748b; margin-top: 2px; }
        img { width: 100px; height: 100px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                @include('pdf.partials.branded-shop-name', ['shop' => $shop])
                <div class="title">{{ $title }}</div>
                <div class="name">{{ $name }}</div>
@if ($number)
                <div class="number">No. {{ $number }}</div>
@endif
            </td>
            <td style="width: 104px; text-align: right;"><img src="{{ $qr }}" alt="QR"></td>
        </tr>
    </table>
</body>
</html>
