<?php

namespace App\Actions;

use App\Enums\PurchaseOrderStatus;
use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\PurchaseOrder;
use App\Models\Scopes\ExcludesCancelledScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelPurchaseOrder
{
    /** Only with nothing received against it. Once goods have arrived, it's closed short instead. */
    public static function handle(PurchaseOrder $order, ?int $userId, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $userId, $reason) {
            $order = PurchaseOrder::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($order->id)->lockForUpdate()->firstOrFail();

            SavePurchaseOrder::assertEnabled($order->shop_id);

            if ($order->isCancelled()) {
                throw new \RuntimeException('This order has already been cancelled.');
            }

            if ($order->hasActiveGrn()) {
                throw ValidationException::withMessages(['order' => 'Goods have been received against this order, so it can\'t be cancelled. Close it short instead.']);
            }

            $order->update([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => filled($reason) ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $order->shop_id,
                'revisionable_type' => PurchaseOrder::class,
                'revisionable_id' => $order->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => filled($reason) ? $reason : null,
                'snapshot' => ['vendor_id' => $order->vendor_id, 'order_date' => $order->order_date->toDateString()],
                'created_at' => now(),
            ]);

            return $order;
        });
    }

    /** The owner isn't expecting the rest. What arrived stays, along with what's owed for it. */
    public static function closeShort(PurchaseOrder $order, ?int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $userId) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            SavePurchaseOrder::assertEnabled($order->shop_id);

            if (! $order->hasActiveGrn()) {
                throw ValidationException::withMessages(['order' => 'Nothing has been received against this order. Cancel it instead.']);
            }

            if (! $order->canReceive()) {
                throw ValidationException::withMessages(['order' => 'This order is already '.strtolower($order->status->label()).'.']);
            }

            $order->forceFill(['closed_at' => now(), 'closed_by' => $userId]);
            $order->recomputeStatus();

            return $order;
        });
    }
}
