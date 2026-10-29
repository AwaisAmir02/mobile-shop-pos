<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? $label['barcode'] }}</title>
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #151a20; margin: 0; padding: 0; }
        .break-after { page-break-after: always; }

        .roll-label {
            width: 50mm;
            height: 30mm;
            padding: 1.5mm 2mm;
            text-align: center;
            overflow: hidden;
        }
        .shop { font-size: 6pt; color: #64748b; }
        .name { font-size: 7pt; font-weight: bold; margin-top: 0.5mm; white-space: nowrap; overflow: hidden; }
        .barcode-img { height: 13mm; max-width: 46mm; margin-top: 0.5mm; }
        .number { font-size: 6.5pt; letter-spacing: 0.5px; }
        .price { font-size: 7pt; font-weight: bold; margin-top: 0.3mm; }

        table.sheet { width: 210mm; border-collapse: collapse; table-layout: fixed; }
        table.sheet td {
            width: 63.5mm;
            height: 33.9mm;
            padding: 3mm 3mm;
            text-align: center;
            vertical-align: middle;
            overflow: hidden;
        }
        table.sheet td .name { font-size: 8pt; }
        table.sheet td .barcode-img { height: 14mm; max-width: 56mm; }
    </style>
</head>
<body>
@php
    $labels ??= array_fill(0, $copies, $label);
    $total = count($labels);
@endphp
@if ($layout === 'roll')
    @foreach ($labels as $i => $item)
        <div class="roll-label @if ($i < $total - 1) break-after @endif">
            @include('pdf.partials.product-label-content', ['label' => $item])
        </div>
    @endforeach
@else
    @php $perPage = $columns * $rowsPerPage; @endphp
    @for ($page = 0; $page < (int) ceil($total / $perPage); $page++)
        <table class="sheet @if ($page < (int) ceil($total / $perPage) - 1) break-after @endif">
            @for ($row = 0; $row < $rowsPerPage; $row++)
                <tr>
                    @for ($col = 0; $col < $columns; $col++)
                        @php $index = $page * $perPage + $row * $columns + $col; @endphp
                        <td>
                            @if ($index < $total)
                                @include('pdf.partials.product-label-content', ['label' => $labels[$index]])
                            @endif
                        </td>
                    @endfor
                </tr>
            @endfor
        </table>
    @endfor
@endif
</body>
</html>
