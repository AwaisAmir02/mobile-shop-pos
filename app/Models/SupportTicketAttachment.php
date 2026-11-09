<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * An image on a support message, on the private disk. Served only by
 * support.attachment (shop side, ticket visibility checked) and
 * admin.support.attachment (super admin).
 */
class SupportTicketAttachment extends Model
{
    protected $fillable = [
        'support_ticket_message_id',
        'path',
        'original_name',
        'size',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }

    /** Same hardening as payment proofs: never sniffed into something runnable. */
    public function response(): Response
    {
        $disk = Storage::disk(config('support.disk'));

        abort_unless($disk->exists($this->path), 404);

        return $disk->response($this->path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'",
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
