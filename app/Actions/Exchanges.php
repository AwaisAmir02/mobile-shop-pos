<?php

namespace App\Actions;

use App\Enums\SalePaymentMethod;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Support\BatchExpiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An exchange: goods from an earlier sale come back and others go out, in one
 * go. It is exactly a return (credit note) plus a new sale (invoice), linked,
 * and saved together or not at all.
 *
 * What the returned goods are due back (their value, less anything still
 * owed on the old sale) pays for the new sale first, as an Exchange payment
 * that moves no money. If the new sale costs more, the customer pays the rest
 * as on any sale. If it costs less, the leftover is refunded as any return's
 * would be: money where the shop gives it, store credit where it doesn't.
 */
class Exchanges
{
    /**
     * @param  array<string, array{quantity: mixed, disposition?: string}>  $returnLines  by SaleReturns::groups() key
     * @param  array<int, array<string, mixed>>  $newLines  CreateSale lines
     * @return array{return: SaleReturn, sale: Sale}
     */
    public static function handle(
        Sale $original,
        array $returnLines,
        array $newLines,
        ?int $customerId,
        ?int $userId,
        float $amountPaid = 0.0,
        ?string $paymentMethod = null,
        ?int $shopAccountId = null,
        float $storeCredit = 0.0,
        float $invoiceDiscount = 0.0,
        string $refundTo = SaleReturns::REFUND_AUTO,
        ?string $refundMethod = null,
        ?int $refundAccountId = null,
        ?string $reason = null,
        ?string $lateReason = null,
        bool $byOwner = false,
        ?array $prescription = null,
    ): array {
        if ($newLines === []) {
            throw ValidationException::withMessages(['cart' => 'Add what the customer is taking instead. To only take goods back, use Return Items on the sale.']);
        }

        return DB::transaction(function () use ($original, $returnLines, $newLines, $customerId, $userId, $amountPaid, $paymentMethod, $shopAccountId, $storeCredit, $invoiceDiscount, $refundTo, $refundMethod, $refundAccountId, $reason, $lateReason, $byOwner, $prescription) {
            $original = Sale::withoutGlobalScopes()->whereKey($original->id)->lockForUpdate()->firstOrFail();

            if ($original->isCancelled()) {
                throw ValidationException::withMessages(['lines' => 'A cancelled sale can\'t be exchanged.']);
            }

            $today = BatchExpiry::today();
            $dueBack = SaleReturns::refundableFor($original, $returnLines);

            $sale = CreateSale::handle(
                lines: $newLines,
                invoiceDiscount: $invoiceDiscount,
                customerId: $customerId,
                userId: $userId,
                saleDate: $today,
                amountPaid: $amountPaid,
                paymentMethod: $paymentMethod,
                shopAccountId: $shopAccountId,
                storeCredit: $storeCredit,
                // Taken at the register, so the shop's promotions apply as on any sale there.
                applyPromotions: true,
                prescription: $prescription,
            );

            $room = round((float) $sale->total - $amountPaid - round($storeCredit, 2), 2);

            if ($room < 0) {
                throw ValidationException::withMessages(['amountPaidNow' => 'The amount paid is more than the new sale (Rs '.number_format((float) $sale->total, 2).').']);
            }

            $applied = round(min($dueBack, $room), 2);

            if ($applied > 0) {
                $sale->payments()->create([
                    'shop_id' => $sale->shop_id,
                    'user_id' => $userId,
                    'amount' => $applied,
                    'payment_date' => now()->toDateString(),
                    'payment_method' => SalePaymentMethod::Exchange->value,
                ]);
            }

            $return = SaleReturns::create(
                $original,
                $returnLines,
                $refundMethod,
                $refundAccountId,
                $reason,
                $userId,
                $today,
                $lateReason,
                $byOwner,
                refundTo: $refundTo,
                creditCustomerId: $customerId,
                toExchange: $applied,
                exchangeSaleId: $sale->id,
            );

            // The preview and the return worked the value out the same way, so they agree to the paisa.
            if (round((float) $return->refund_exchange, 2) !== $applied) {
                throw new \RuntimeException('Exchange value mismatch: '.$return->refund_exchange.' against '.$applied.'.');
            }

            return ['return' => $return, 'sale' => $sale->fresh(['items', 'payments'])];
        });
    }
}
