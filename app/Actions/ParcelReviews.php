<?php

namespace App\Actions;

use App\Enums\CourierKey;
use App\Enums\OnlineTool;
use App\Enums\ShipmentStatus;
use App\Models\CustomerReview;
use App\Models\Shipment;
use App\Models\Shop;
use App\Services\WhatsApp\CustomerNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Asking for a review once a parcel is delivered (O8), for online sellers
 * (OnlineTool::ReviewRequests).
 *
 * Every delivered parcel gets a feedback page (/r/{token}) where the buyer picks
 * stars and writes a line. With WhatsApp messaging on, the request goes out by
 * itself a few hours after delivery (whatsapp:follow-ups) as the approved
 * review_request template, whose rating buttons the chatbot already answers;
 * the rating it captures is tied to this parcel. Without it, the shop sends the
 * page's link from Shipments in one tap, from its own WhatsApp.
 *
 * Reviews land in CustomerReview, beside the WhatsApp-order ones, so Marketing's
 * reviews screen shows them all. Daraz orders are left out: Daraz reviews its own.
 */
class ParcelReviews
{
    /** Hours after delivery before asking; days after which it's too late to. Same as WhatsApp orders. */
    public const DELAY_HOURS = \App\Console\Commands\SendWhatsAppFollowUps::REVIEW_DELAY_HOURS;

    public const WINDOW_DAYS = \App\Console\Commands\SendWhatsAppFollowUps::REVIEW_WINDOW_DAYS;

    public static function enabled(Shop $shop): bool
    {
        return $shop->usesTool(OnlineTool::ReviewRequests);
    }

    public static function link(Shipment $shipment): string
    {
        if ($shipment->review_token === null) {
            $shipment->forceFill(['review_token' => Str::random(40)])->save();
        }

        return route('parcel-review.show', $shipment->review_token);
    }

    /**
     * Parcels to ask about now, across every shop that sends them by WhatsApp.
     *
     * @return Collection<int, Shipment>
     */
    public static function due(int $limit): Collection
    {
        $shopIds = Shop::where('whatsapp_messaging_enabled', true)->get()->filter(fn (Shop $shop) => self::enabled($shop))->pluck('id');

        if ($shopIds->isEmpty()) {
            return collect();
        }

        return Shipment::withoutGlobalScopes()
            ->whereIn('shop_id', $shopIds)
            ->where('status', ShipmentStatus::Delivered->value)
            ->where('courier', '!=', CourierKey::Daraz->value)
            ->whereNull('whatsapp_order_id')
            ->whereNull('review_requested_at')
            ->whereNotNull('sale_id')
            ->whereBetween('delivered_at', [now()->subDays(self::WINDOW_DAYS), now()->subHours(self::DELAY_HOURS)])
            ->orderBy('delivered_at')
            ->limit($limit)
            ->get();
    }

    /** Sends the WhatsApp request. Marked asked first, so a failure is never sent twice. */
    public static function sendByWhatsApp(Shipment $shipment, CustomerNotifications $notifications): void
    {
        $shipment->forceFill(['review_requested_at' => now()])->save();
        self::link($shipment);
        $notifications->parcelReviewRequest($shipment->fresh());
    }

    /** The shop sends the link itself: a wa.me address with the message ready. */
    public static function shareUrl(Shipment $shipment): ?string
    {
        $phone = \App\Support\PhoneNumber::normalize($shipment->consignee_phone);

        if ($phone === null) {
            return null;
        }

        $shipment->forceFill(['review_requested_at' => $shipment->review_requested_at ?? now()])->save();
        $shop = Shop::findOrFail($shipment->shop_id);

        return 'https://wa.me/'.$phone.'?text='.rawurlencode('Assalam o Alaikum '.$shipment->consignee_name.'! Your order '.$shipment->reference.' from '.$shop->invoiceDisplayName().' was delivered. How was it? Tell us here: '.self::link($shipment));
    }

    /** What the buyer left on the feedback page. A second visit updates it. */
    public static function submit(Shipment $shipment, int $rating, ?string $comment, bool $consent, ?string $displayName): CustomerReview
    {
        if ($shipment->status !== ShipmentStatus::Delivered) {
            throw ValidationException::withMessages(['rating' => 'This order isn\'t marked delivered yet.']);
        }

        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages(['rating' => 'Choose from 1 to 5 stars.']);
        }

        $customerId = \App\Models\Sale::withoutGlobalScopes()->whereKey($shipment->sale_id)->value('customer_id');

        return CustomerReview::withoutGlobalScopes()->updateOrCreate(
            ['shop_id' => $shipment->shop_id, 'shipment_id' => $shipment->id],
            [
                'customer_id' => $customerId,
                'rating' => $rating,
                'comment' => filled($comment) ? mb_substr(trim($comment), 0, 1000) : null,
                'display_name' => mb_substr(trim((string) ($displayName ?: $shipment->consignee_name)), 0, 100) ?: null,
                'publish_consent' => $consent,
                'consent_at' => $consent ? now() : null,
                'status' => 'complete',
            ],
        );
    }
}
