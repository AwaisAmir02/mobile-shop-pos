@extends('emails.layout')

@section('heading', count($items) === 1 ? 'A product is running low' : count($items).' products are running low')

@section('body')
    <p style="margin:0 0 12px;">These products at <strong>{{ $shop->name }}</strong> just dropped to {{ \App\Models\Product::LOW_STOCK_THRESHOLD }} or fewer:</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:21px;border-collapse:collapse;">
        @foreach ($items as $item)
            <tr>
                <td style="padding:6px 0;border-top:1px solid #e2e8f0;">{{ $item['name'] }}</td>
                <td style="padding:6px 0;border-top:1px solid #e2e8f0;text-align:right;white-space:nowrap;color:#b45309;font-weight:bold;">{{ $item['stock'] }} left</td>
            </tr>
        @endforeach
    </table>
@endsection

@section('action', 'View products')
@section('action_url', route('products.index'))
