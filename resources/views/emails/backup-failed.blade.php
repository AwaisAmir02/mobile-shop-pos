<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;padding:24px;">
        <h1 style="margin:0 0 12px;font-size:18px;color:#b91c1c;">
            {{ $reason === 'stale' ? 'No successful backup recently' : 'Backup failed' }}
        </h1>
        @if ($reason === 'stale')
            <p style="font-size:14px;line-height:21px;">No backup has completed in the last {{ config('backup.stale_after_hours') }} hours. The scheduled job may have stopped — check that the cPanel cron for <code>schedule:run</code> is still active.</p>
        @else
            <p style="font-size:14px;line-height:21px;">The {{ $run->trigger }} backup started at {{ $run->started_at?->timezone('Asia/Karachi')->format('d M Y, h:i A') }} PKT did not complete.</p>
            <p style="font-size:13px;line-height:20px;background:#fef2f2;border-radius:8px;padding:12px;">{{ $run->error }}</p>
        @endif
        <p style="font-size:14px;line-height:21px;">Last successful backup: <strong>{{ $lastSuccess?->finished_at?->timezone('Asia/Karachi')->format('d M Y, h:i A').' PKT' ?? 'never' }}</strong></p>
        <p style="font-size:13px;color:#64748b;">Open Super Admin → Backups to see details and run a backup manually.</p>
    </div>
</body>
</html>
