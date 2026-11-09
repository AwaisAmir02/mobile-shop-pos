<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;padding:24px;">
        <h1 style="margin:0 0 4px;font-size:18px;">New support ticket {{ $ticket->number() }}</h1>
        <p style="margin:0 0 16px;font-size:13px;color:#64748b;">{{ $ticket->created_at?->timezone('Asia/Karachi')->format('d M Y, h:i A') }} PKT</p>

        <table style="width:100%;font-size:14px;line-height:21px;border-collapse:collapse;">
            <tr><td style="padding:2px 12px 2px 0;color:#64748b;width:90px;">Shop</td><td><strong>{{ $ticket->shop?->name }}</strong></td></tr>
            <tr><td style="padding:2px 12px 2px 0;color:#64748b;">From</td><td>{{ $ticket->user?->name ?? 'Removed user' }}{{ $ticket->user?->is_owner ? ' (owner)' : '' }} · {{ $ticket->user?->loginLabel() }}</td></tr>
            <tr><td style="padding:2px 12px 2px 0;color:#64748b;">Screen</td><td>{{ $ticket->screenLabel() ?? 'Not linked to a screen' }}@if ($ticket->screen_url) <span style="color:#64748b;">({{ $ticket->screen_url }})</span>@endif</td></tr>
            @if ($ticket->device)
                <tr><td style="padding:2px 12px 2px 0;color:#64748b;">Device</td><td>{{ $ticket->device }}</td></tr>
            @endif
        </table>

        <div style="margin:16px 0;padding:12px;background:#f8fafc;border-radius:8px;font-size:14px;line-height:21px;white-space:pre-line;">{{ $firstMessage?->body }}</div>

        @if ($firstMessage && $firstMessage->attachments->isNotEmpty())
            <p style="font-size:13px;color:#64748b;">{{ $firstMessage->attachments->count() }} {{ Str::plural('photo', $firstMessage->attachments->count()) }} attached — open the ticket to see {{ $firstMessage->attachments->count() === 1 ? 'it' : 'them' }}.</p>
        @endif

        <p style="margin-top:20px;"><a href="{{ route('admin.support.show', $ticket) }}" style="display:inline-block;background:#1e3a8a;color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;padding:10px 16px;border-radius:999px;">Open the ticket</a></p>
    </div>
</body>
</html>
