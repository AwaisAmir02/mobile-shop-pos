<?php

namespace App\Actions;

use App\Actions\Support\OpenSupportTicket;
use App\Actions\Support\ReplyToSupportTicket;
use App\Enums\StoreDriver;
use App\Enums\WebsiteRequestStatus;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\WebsiteRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Build my website" (O9): intake and tracking only. Nothing builds a site.
 *
 * The request opens a support ticket whose first message is the requirements,
 * so the shop and our team talk it through, with photos and files, in the
 * support thread both sides already use. Our team moves the request along
 * (in progress, declined) and, once the site is handed over, marks it
 * delivered with the address and the shop's own-website store connection the
 * site was given (Settings › Online Store). Pricing and invoicing are agreed
 * outside the app.
 */
class WebsiteRequests
{
    public const GOALS = [
        'catalogue' => 'Show my products, customers order on WhatsApp',
        'orders' => 'Take orders on the site, cash on delivery',
        'payments' => 'Take orders and online payments',
    ];

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'about' => ['required', 'string', 'min:20', 'max:2000'],
            'goal' => ['required', 'in:'.implode(',', array_keys(self::GOALS))],
            'products' => ['required', 'integer', 'min:1', 'max:100000'],
            'domain' => ['nullable', 'string', 'max:255'],
            'references' => ['nullable', 'string', 'max:1000'],
            'deadline' => ['nullable', 'date', 'after:today'],
            'phone' => ['required', 'string', 'max:30'],
        ];
    }

    /** @param  array<string, mixed>  $requirements  validated by rules() */
    public static function submit(User $user, array $requirements, array $images = [], ?string $userAgent = null): WebsiteRequest
    {
        if (WebsiteRequest::where('shop_id', $user->shop_id)->whereIn('status', [WebsiteRequestStatus::Submitted->value, WebsiteRequestStatus::InProgress->value])->exists()) {
            throw ValidationException::withMessages(['about' => 'You already have a website request open. Add to it in its conversation.']);
        }

        return DB::transaction(function () use ($user, $requirements, $images, $userAgent) {
            $ticket = app(OpenSupportTicket::class)->handle($user, self::summary($requirements), $images, route('website-requests.index'), 'Website request', $userAgent);

            return WebsiteRequest::create([
                'shop_id' => $user->shop_id,
                'support_ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'status' => WebsiteRequestStatus::Submitted,
                'requirements' => $requirements,
            ]);
        });
    }

    /** The requirements as the ticket's first message, readable by either side. */
    public static function summary(array $requirements): string
    {
        return implode("\n", array_filter([
            'Website request',
            'About the business: '.$requirements['about'],
            'The site should: '.(self::GOALS[$requirements['goal']] ?? $requirements['goal']),
            'Products to show: about '.$requirements['products'],
            filled($requirements['domain'] ?? null) ? 'Domain: '.$requirements['domain'] : 'Domain: needs one',
            filled($requirements['references'] ?? null) ? 'Sites they like: '.$requirements['references'] : null,
            filled($requirements['deadline'] ?? null) ? 'Wanted by: '.$requirements['deadline'] : null,
            'Contact: '.$requirements['phone'],
        ]));
    }

    public static function setStatus(WebsiteRequest $request, WebsiteRequestStatus $status, User $admin, ?string $note = null): void
    {
        if ($status === WebsiteRequestStatus::Fulfilled) {
            throw ValidationException::withMessages(['status' => 'Use Mark delivered, with the site and its store connection.']);
        }

        $request->update(['status' => $status, 'admin_note' => filled($note) ? mb_substr($note, 0, 1000) : $request->admin_note]);

        if ($request->ticket !== null && filled($note)) {
            app(ReplyToSupportTicket::class)->handle($request->ticket, $admin, $status->label().': '.$note);
        }
    }

    /**
     * The site is handed over: its address, and the shop's own-website store
     * connection (API token and webhook secret) it was set up with.
     */
    public static function fulfil(WebsiteRequest $request, ?int $storeConnectionId, string $siteUrl, ?string $note, User $admin): void
    {
        $connection = $storeConnectionId === null ? null : StoreConnection::withoutGlobalScopes()->where('shop_id', $request->shop_id)
            ->where('driver', StoreDriver::Api->value)->whereNull('removed_at')->find($storeConnectionId);

        if ($storeConnectionId !== null && $connection === null) {
            throw ValidationException::withMessages(['storeConnectionId' => 'Choose one of this shop\'s own-website connections.']);
        }

        if (! filter_var($siteUrl, FILTER_VALIDATE_URL) || ! str_starts_with($siteUrl, 'https://')) {
            throw ValidationException::withMessages(['siteUrl' => 'Enter the site\'s https:// address.']);
        }

        DB::transaction(function () use ($request, $connection, $siteUrl, $note, $admin) {
            $request->update([
                'status' => WebsiteRequestStatus::Fulfilled,
                'store_connection_id' => $connection?->id,
                'site_url' => mb_substr($siteUrl, 0, 255),
                'admin_note' => filled($note) ? mb_substr($note, 0, 1000) : $request->admin_note,
                'fulfilled_at' => now(),
                'fulfilled_by' => $admin->id,
            ]);

            if ($request->ticket !== null) {
                app(ReplyToSupportTicket::class)->handle($request->ticket, $admin, 'Your website is ready: '.$siteUrl.($connection ? ' (connected as "'.$connection->name.'" under Settings › Online Store)' : '').(filled($note) ? "\n".$note : ''));
            }
        });
    }
}
