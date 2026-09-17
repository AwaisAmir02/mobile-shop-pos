<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #1e293b;
            font-size: 10px;
            margin: 0;
            padding: 24px;
        }
        .header {
            border-bottom: 2px solid #049669;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .shop-name {
            font-size: 15px;
            font-weight: bold;
            color: #037a56;
        }
        .report-title {
            font-size: 13px;
            font-weight: bold;
            margin-top: 4px;
        }
        .meta {
            font-size: 9px;
            color: #64748b;
            margin-top: 4px;
        }
        table.data {
            width: 100%;
            border-collapse: collapse;
        }
        table.data thead {
            display: table-header-group;
        }
        table.data tr {
            page-break-inside: avoid;
        }
        table.data th {
            background-color: #e2e8f0;
            color: #334155;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            text-align: left;
            padding: 6px 8px;
            border-bottom: 1px solid #cbd5e1;
        }
        table.data td {
            padding: 5px 8px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 9.5px;
            vertical-align: top;
        }
        table.data tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .empty {
            padding: 16px 0;
            text-align: center;
            color: #94a3b8;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="shop-name">{{ $shopName }}</div>
        <div class="report-title">{{ $title }}</div>
        <div class="meta">
            Generated: {{ $generatedAt->format('d M Y, h:i A') }}<br>
            Filters: {{ $filtersSummary }}
        </div>
    </div>

    @if (empty($rows))
        <p class="empty">No records match the current filters.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    @foreach ($headers as $header)
                        <th>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
