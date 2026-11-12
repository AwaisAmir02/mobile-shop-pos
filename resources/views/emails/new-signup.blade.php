@extends('emails.layout')

@section('heading', 'New signup: '.$shop->name)

@section('body')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:21px;border-collapse:collapse;">
        <tr><td style="padding:2px 12px 2px 0;color:#64748b;width:90px;">Shop</td><td><strong>{{ $shop->name }}</strong></td></tr>
        <tr><td style="padding:2px 12px 2px 0;color:#64748b;">Owner</td><td>{{ $owner->name }}</td></tr>
        @if ($owner->email)
            <tr><td style="padding:2px 12px 2px 0;color:#64748b;">Email</td><td>{{ $owner->email }}</td></tr>
        @endif
        @if ($owner->phone ?? $shop->phone)
            <tr><td style="padding:2px 12px 2px 0;color:#64748b;">Phone</td><td>{{ $owner->phone ?? $shop->phone }}</td></tr>
        @endif
        <tr><td style="padding:2px 12px 2px 0;color:#64748b;">Plan</td><td>{{ $plan?->name ?? 'None' }}</td></tr>
        @if ($shop->trial_ends_at)
            <tr><td style="padding:2px 12px 2px 0;color:#64748b;">Trial ends</td><td>{{ $shop->trial_ends_at->timezone('Asia/Karachi')->format('d M Y') }}</td></tr>
        @endif
        <tr><td style="padding:2px 12px 2px 0;color:#64748b;">Signed up</td><td>{{ $shop->created_at?->timezone('Asia/Karachi')->format('d M Y, h:i A') }} PKT</td></tr>
    </table>
@endsection

@section('action', 'Open the shop')
@section('action_url', route('admin.shops.show', $shop))
@section('footer', 'Sent to the platform inbox (SIGNUP_ALERT_EMAIL).')
