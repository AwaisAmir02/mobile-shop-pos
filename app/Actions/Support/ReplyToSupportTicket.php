<?php

namespace App\Actions\Support;

use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * D13: a reply in a support thread, from either side.
 *
 * A shop reply on a resolved ticket reopens it (owner decision 5). An admin
 * reply never changes the status; the admin sets that by hand.
 */
class ReplyToSupportTicket
{
    public function __construct(protected StoreSupportMessage $messages) {}

    /**
     * @param  list<UploadedFile>  $images
     */
    public function handle(SupportTicket $ticket, User $author, string $body, array $images = []): SupportTicketMessage
    {
        abort_unless($ticket->isVisibleTo($author), 404);

        $fromAdmin = (bool) $author->is_super_admin;
        $key = 'support-reply:'.$author->id;

        if (! $fromAdmin && RateLimiter::tooManyAttempts($key, config('support.messages_per_hour'))) {
            throw new RuntimeException('Too many messages in the last hour. Please try again later.');
        }

        $message = DB::transaction(function () use ($ticket, $author, $body, $images, $fromAdmin) {
            $message = $this->messages->handle($ticket, $author, $body, $images);
            $now = now();

            $changes = ['last_message_at' => $now];

            if ($fromAdmin) {
                $changes += ['last_admin_message_at' => $now, 'admin_read_at' => $now];
            } else {
                $changes += ['last_shop_message_at' => $now, 'shop_read_at' => $now];

                if ($ticket->status === SupportTicketStatus::Resolved) {
                    $changes += ['status' => SupportTicketStatus::Open, 'resolved_at' => null];
                }
            }

            $ticket->update($changes);

            return $message;
        });

        if (! $fromAdmin) {
            RateLimiter::hit($key, 3600);
        }

        return $message;
    }
}
