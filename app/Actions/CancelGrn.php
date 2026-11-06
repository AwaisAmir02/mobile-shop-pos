<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Models\Grn;
use App\Models\ModuleRevision;
use App\Models\PurchaseOrder;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockIn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a delivery that was recorded by mistake. All or nothing, and refused
 * once any of its stock has been sold or written off, or anything paid: this
 * never reverses a sale, and it isn't a return to the vendor.
 */
class CancelGrn
{
    public static function handle(Grn $grn, ?int $userId, ?string $reason = null): Grn
    {
        return DB::transaction(function () use ($grn, $userId, $reason) {
            $grn = Grn::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($grn->id)->lockForUpdate()->firstOrFail();

            if ($grn->isCancelled()) {
                throw new \RuntimeException('This goods receipt has already been cancelled.');
            }

            $grn->load('lines');

            $entries = StockIn::whereIn('grn_line_id', $grn->lines->pluck('id'))->with('payments')->lockForUpdate()->get();

            foreach ($entries as $entry) {
                if ((float) $entry->amount_paid > 0 || $entry->payments->isNotEmpty()) {
                    throw ValidationException::withMessages(['grn' => "A payment is recorded against {$entry->product_name} on this receipt, so it can't be cancelled."]);
                }
            }

            foreach ($entries as $entry) {
                CancelStockIn::handle($entry, $userId, $reason, fromGrn: true);
            }

            $grn->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => filled($reason) ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $grn->shop_id,
                'revisionable_type' => Grn::class,
                'revisionable_id' => $grn->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => filled($reason) ? $reason : null,
                'snapshot' => [
                    'purchase_order_id' => $grn->purchase_order_id,
                    'received_on' => $grn->received_on->toDateString(),
                    'lines' => $grn->lines->map(fn ($line) => ['po_line_id' => $line->po_line_id, 'product_name' => $line->product_name, 'quantity' => $line->quantity, 'unit' => $line->unit->value])->all(),
                ],
                'created_at' => now(),
            ]);

            $order = PurchaseOrder::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($grn->purchase_order_id)->lockForUpdate()->first();
            $order?->recomputeStatus();

            return $grn;
        });
    }
}
