<?php

namespace App\Actions\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes one message and its images. Callers wrap it in a transaction; if the
 * database part fails, the files already written are removed again.
 */
class StoreSupportMessage
{
    /**
     * @param  list<UploadedFile>  $images
     */
    public function handle(SupportTicket $ticket, User $author, string $body, array $images = []): SupportTicketMessage
    {
        $body = trim($body);

        if ($body === '' && $images === []) {
            throw new RuntimeException('Write a message or add a photo.');
        }

        if (count($images) > config('support.max_images')) {
            throw new RuntimeException('Up to '.config('support.max_images').' photos per message.');
        }

        $disk = Storage::disk(config('support.disk'));
        $stored = [];

        try {
            $message = SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $author->id,
                'from_admin' => (bool) $author->is_super_admin,
                'body' => $body === '' ? null : mb_substr($body, 0, config('support.message_max_chars')),
            ]);

            foreach ($images as $image) {
                $path = $image->store('support/'.$ticket->shop_id.'/'.$ticket->id, config('support.disk'));
                $stored[] = $path;

                $message->attachments()->create([
                    'path' => $path,
                    'original_name' => mb_substr($image->getClientOriginalName(), 0, 255),
                    'size' => (int) $image->getSize(),
                ]);
            }
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                $disk->delete($path);
            }

            throw $e;
        }

        return $message;
    }
}
