<?php

namespace App\Actions\Support;

use App\Enums\SupportTicketStatus;
use App\Mail\NewSupportTicketMail;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\SupportScreen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * A shop user opens a support ticket. Any active shop user may; there is
 * no screen permission for it on purpose.
 */
class OpenSupportTicket
{
    public function __construct(protected StoreSupportMessage $messages) {}

    /**
     * @param  list<UploadedFile>  $images
     */
    public function handle(User $user, string $body, array $images = [], ?string $fromAddress = null, ?string $fromTitle = null, ?string $userAgent = null): SupportTicket
    {
        if ($user->is_super_admin || ! $user->shop_id) {
            throw new RuntimeException('Only shop users can open a support ticket.');
        }

        $key = 'support-open:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, config('support.tickets_per_hour'))) {
            throw new RuntimeException('You have opened several tickets in the last hour. Please add to an existing ticket, or try again later.');
        }

        $screen = SupportScreen::resolve($fromAddress, $fromTitle);
        $now = now();

        $ticket = DB::transaction(function () use ($user, $body, $images, $screen, $userAgent, $now) {
            $ticket = SupportTicket::create([
                'shop_id' => $user->shop_id,
                'user_id' => $user->id,
                // Only worth noting when the shop has more than one branch.
                'branch_id' => BranchContext::current()->hasMultipleBranches((int) $user->shop_id) ? BranchContext::current()->recordingBranchId() : null,
                'status' => SupportTicketStatus::Open,
                'screen_route' => $screen['route'] ?? null,
                'screen_url' => $screen['url'] ?? null,
                'screen_title' => $screen['title'] ?? null,
                'device' => SupportTicket::describeDevice($userAgent),
                'last_message_at' => $now,
                'last_shop_message_at' => $now,
                'shop_read_at' => $now,
            ]);

            $this->messages->handle($ticket, $user, $body, $images);

            return $ticket;
        });

        RateLimiter::hit($key, 3600);

        if (filled(config('support.alert_email'))) {
            try {
                Mail::to(config('support.alert_email'))->queue(new NewSupportTicketMail($ticket));
            } catch (\Throwable $e) {
                Log::warning('Support ticket alert email could not be queued.', ['ticket' => $ticket->id, 'error' => $e->getMessage()]);
            }
        }

        return $ticket;
    }
}
