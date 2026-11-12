@extends('emails.layout')

@section('heading', 'Your subscription is ending soon')

@section('body')
    <p style="margin:0 0 12px;">Hi, your {{ $planName ? $planName.' plan' : 'subscription' }} for <strong>{{ $shop->name }}</strong> has {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }} left. The paid period ends on <strong>{{ $lastDay->format('d M Y') }}</strong>.</p>
    <p style="margin:0;">Pay now so nothing stops. Once the period ends unpaid, the shop is locked until the payment is confirmed.</p>
@endsection

@section('action', 'Pay now')
@section('action_url', route('billing.index'))
