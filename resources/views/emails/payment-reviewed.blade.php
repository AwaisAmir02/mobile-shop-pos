@extends('emails.layout')

@section('heading', $confirmed ? 'Payment confirmed' : 'Payment not confirmed')

@section('body')
    @if ($confirmed)
        <p style="margin:0 0 12px;">We’ve confirmed your payment of <strong>Rs {{ number_format((float) $payment->amount) }}</strong> for <strong>{{ $payment->shop?->name }}</strong> ({{ $purpose }}).</p>
        <p style="margin:0;">It’s active now. Thank you!</p>
    @else
        <p style="margin:0 0 12px;">We couldn’t confirm the payment of <strong>Rs {{ number_format((float) $payment->amount) }}</strong> you sent for <strong>{{ $payment->shop?->name }}</strong> ({{ $purpose }}).</p>
        @if (filled($payment->rejection_reason))
            <p style="margin:0 0 12px;padding:12px;background:#fef2f2;border-radius:8px;color:#991b1b;">{{ $payment->rejection_reason }}</p>
        @endif
        <p style="margin:0;">Please check the details and submit it again.</p>
    @endif
@endsection

@section('action', $confirmed ? 'View billing' : 'Submit again')
@section('action_url', route('billing.index'))
