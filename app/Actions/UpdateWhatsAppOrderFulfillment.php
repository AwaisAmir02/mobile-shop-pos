<?php

namespace App\Actions;

use App\Enums\FulfillmentStatus;
use App\Enums\WhatsAppOrderStatus;
use App\Models\CustomerNotification;
use App\Models\WhatsAppOrder;
use App\Services\WhatsApp\CustomerNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateWhatsAppOrderFulfillment
{
    public static function handle(WhatsAppOrder $order, FulfillmentStatus $status, int $userId, bool $notify = true): ?CustomerNotification
    {
        $order = DB::transaction(function () use ($order, $status) {
            $order = WhatsAppOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== WhatsAppOrderStatus::Accepted) {
                throw ValidationException::withMessages(['fulfillment' => 'Only accepted orders can be updated.']);
            }

            if ($order->fulfillment_status === FulfillmentStatus::Delivered) {
                throw ValidationException::withMessages(['fulfillment' => 'This order is already delivered.']);
            }

            if ($order->fulfillment_status !== null && $status->step() <= $order->fulfillment_status->step()) {
                throw ValidationException::withMessages(['fulfillment' => 'This order is already past "'.$status->label().'".']);
            }

            $order->update([
                'fulfillment_status' => $status,
                'fulfillment_updated_at' => now(),
                'delivered_at' => $status === FulfillmentStatus::Delivered ? now() : $order->delivered_at,
            ]);

            return $order;
        });

        return $notify ? app(CustomerNotifications::class)->orderFulfillment($order, $status, $userId) : null;
    }
}
