<?php

namespace App\Actions;

use App\Enums\WhatsAppOrderStatus;
use App\Jobs\SendWhatsAppOrderUpdate;
use App\Models\WhatsAppOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectWhatsAppOrder
{
    public static function handle(WhatsAppOrder $order, string $reason, int $userId): WhatsAppOrder
    {
        $order = DB::transaction(function () use ($order, $reason, $userId) {
            $order = WhatsAppOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->isDecidable()) {
                throw ValidationException::withMessages(['order' => 'This order has already been '.strtolower($order->status->label()).'.']);
            }

            $order->update([
                'status' => WhatsAppOrderStatus::Rejected,
                'reject_reason' => $reason,
                'decided_by' => $userId,
                'decided_at' => now(),
            ]);

            return $order;
        });

        SendWhatsAppOrderUpdate::dispatch($order->id)->afterCommit();

        return $order;
    }
}
