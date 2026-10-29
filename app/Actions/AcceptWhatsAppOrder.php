<?php

namespace App\Actions;

use App\Enums\SaleSource;
use App\Enums\WhatsAppOrderStatus;
use App\Jobs\SendWhatsAppOrderUpdate;
use App\Models\Sale;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptWhatsAppOrder
{
    public static function handle(
        WhatsAppOrder $order,
        array $lines,
        float $invoiceDiscount,
        ?int $customerId,
        float $amountPaid,
        int $userId,
    ): Sale {
        $sale = DB::transaction(function () use ($order, $lines, $invoiceDiscount, $customerId, $amountPaid, $userId) {
            $order = WhatsAppOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->isDecidable()) {
                throw ValidationException::withMessages(['order' => 'This order has already been '.strtolower($order->status->label()).'.']);
            }

            if (empty($lines)) {
                throw ValidationException::withMessages(['lines' => 'Keep at least one item on the order.']);
            }

            $sale = CreateSale::handle(
                lines: $lines,
                invoiceDiscount: $invoiceDiscount,
                customerId: $customerId,
                userId: $userId,
                saleDate: now()->toDateString(),
                amountPaid: $amountPaid,
                shopId: $order->shop_id,
                attributes: ['source' => SaleSource::WhatsApp->value],
            );

            $total = (float) $sale->total;

            if ($amountPaid > $total) {
                throw ValidationException::withMessages(['amountPaid' => 'Amount paid cannot be more than the sale total (Rs '.number_format($total, 2).').']);
            }

            if ($customerId === null && $amountPaid < $total) {
                throw ValidationException::withMessages(['customerId' => 'A customer is required when the order is not fully paid.']);
            }

            $order->update([
                'status' => WhatsAppOrderStatus::Accepted,
                'customer_id' => $customerId,
                'sale_id' => $sale->id,
                'decided_by' => $userId,
                'decided_at' => now(),
            ]);

            if ($customerId !== null) {
                WhatsAppContact::whereKey($order->whatsapp_contact_id)->whereNull('customer_id')->update(['customer_id' => $customerId]);
            }

            return $sale;
        });

        SendWhatsAppOrderUpdate::dispatch($order->id)->afterCommit();

        return $sale;
    }
}
