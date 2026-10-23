@if ($shop->branding_enabled && $shop->logoDataUri())
    <table style="border-collapse: collapse;">
        <tr>
            <td style="padding: 0; vertical-align: middle;">
                <img src="{{ $shop->logoDataUri() }}" style="max-height: 32px; max-width: 32px; margin-right: 8px;">
            </td>
            <td style="padding: 0; vertical-align: middle;">
                <div class="shop-name">{{ $shop->invoiceDisplayName() }}</div>
            </td>
        </tr>
    </table>
@else
    <div class="shop-name">{{ $shop->invoiceDisplayName() }}</div>
@endif

@if ($shop->invoiceAddress())
    <div class="shop-address" style="font-size: 10px; color: #64748b; margin-top: 2px; word-wrap: break-word; font-weight: normal;">{{ $shop->invoiceAddress() }}</div>
@endif

