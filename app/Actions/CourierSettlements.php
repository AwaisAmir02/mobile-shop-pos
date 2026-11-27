<?php

namespace App\Actions;

use App\Enums\CourierKey;
use App\Enums\RecordStatus;
use App\Enums\SalePaymentMethod;
use App\Enums\ShipmentStatus;
use App\Models\CourierSettlement;
use App\Models\CourierSettlementItem;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\Shop;
use App\Support\BatchExpiry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Courier payouts (O4). A courier collects cash on delivery for many parcels
 * and pays it out in one go, less what it charged to carry them (and bring
 * refused ones back), the tax it withheld and anything else it took off.
 *
 * Until the payout, a tracked parcel's cash is owed by the courier, not the
 * customer (Shipment::heldByCourier, Sale::owedByCustomer), so it is in no
 * customer's dues. Recording the payout pays each parcel's sale what was
 * collected for it (SalePaymentMethod::Courier, moving no money by itself),
 * and the net is the money that reached the shop's cash or account, once,
 * on the payout's date (MoneyFlow, CourierSettlementBuilder).
 *
 * Cancelling a payout reverses those payments with negative rows (the Contra
 * pattern) and puts its parcels back to waiting, so the courier owes again.
 */
class CourierSettlements
{
    /**
     * Parcels this courier still has to account for: delivered with cash collected,
     * and refused ones (no cash, maybe a return charge), oldest first.
     *
     * @return Collection<int, Shipment>
     */
    public static function waiting(CourierKey $courier, ?string $courierName = null): Collection
    {
        return Shipment::query()
            ->with('sale')
            ->where('courier', $courier->value)
            ->when($courier === CourierKey::Other, fn ($query) => $query->where('courier_name', $courierName))
            ->where('cod_tracked', true)
            ->whereNull('courier_settlement_id')
            ->whereIn('status', [ShipmentStatus::Delivered->value, ShipmentStatus::Returning->value, ShipmentStatus::Returned->value])
            ->orderByRaw('COALESCE(delivered_at, returned_at, booked_at)')
            ->orderBy('id')
            ->get();
    }

    /**
     * What a parcel puts on a payout by default: the cash it was booked to
     * collect if delivered, nothing if it came back, and its return charge.
     *
     * @return array{shipment_id: int, cod_collected: float, courier_charge: float, return_charge: float}
     */
    public static function defaultLine(Shipment $shipment): array
    {
        $delivered = $shipment->status === ShipmentStatus::Delivered;

        return [
            'shipment_id' => $shipment->id,
            'cod_collected' => $delivered ? min((float) $shipment->cod_amount, round((float) $shipment->sale?->amountDue(), 2)) : 0.0,
            'courier_charge' => 0.0,
            'return_charge' => $delivered ? 0.0 : (float) ($shipment->return_charge ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'settlementDate' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:60'],
            'taxWithheld' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'otherDeductions' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines.*.cod_collected' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.courier_charge' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'lines.*.return_charge' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /**
     * Records one payout.
     *
     * @param  list<array{shipment_id: int, cod_collected?: mixed, courier_charge?: mixed, return_charge?: mixed}>  $lines
     * @param  array{settlement_date: string, reference?: ?string, tax_withheld?: mixed, other_deductions?: mixed, payment_method?: ?string, shop_account_id?: ?int, note?: ?string}  $payout
     */
    public static function record(Shop $shop, CourierKey $courier, array $lines, array $payout, ?int $userId): CourierSettlement
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Tick the parcels this payout covers.']);
        }

        $date = (string) $payout['settlement_date'];

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > BatchExpiry::today()) {
            throw ValidationException::withMessages(['settlementDate' => 'Enter the day the money reached you, today or earlier.']);
        }

        return DB::transaction(function () use ($shop, $courier, $lines, $payout, $date, $userId) {
            $ids = array_map(fn (array $line) => (int) $line['shipment_id'], $lines);
            $shipments = Shipment::withoutGlobalScopes()->where('shop_id', $shop->id)->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            $rows = [];

            foreach ($lines as $line) {
                $shipment = $shipments[(int) $line['shipment_id']] ?? null;

                if ($shipment === null || $shipment->courier !== $courier) {
                    throw ValidationException::withMessages(['lines' => 'A parcel on this payout isn\'t one of yours with '.$courier->label().'.']);
                }

                if (! $shipment->cod_tracked || $shipment->courier_settlement_id !== null) {
                    throw ValidationException::withMessages(['lines' => "Parcel {$shipment->tracking_number} is already on a payout."]);
                }

                if (! in_array($shipment->status, [ShipmentStatus::Delivered, ShipmentStatus::Returning, ShipmentStatus::Returned], true)) {
                    throw ValidationException::withMessages(['lines' => "Parcel {$shipment->tracking_number} hasn't been delivered or returned yet."]);
                }

                $collected = round((float) ($line['cod_collected'] ?? 0), 2);
                $charge = round((float) ($line['courier_charge'] ?? 0), 2);
                $returnCharge = round((float) ($line['return_charge'] ?? 0), 2);

                if ($collected < 0 || $charge < 0 || $returnCharge < 0) {
                    throw ValidationException::withMessages(['lines' => 'Amounts on a payout can\'t be below zero.']);
                }

                $sale = Sale::withoutGlobalScopes()->whereKey($shipment->sale_id)->lockForUpdate()->first();

                if ($collected > 0) {
                    if ($shipment->status !== ShipmentStatus::Delivered) {
                        throw ValidationException::withMessages(['lines' => "Parcel {$shipment->tracking_number} came back, so no cash was collected on it."]);
                    }

                    if ($sale === null || $sale->isCancelled()) {
                        throw ValidationException::withMessages(['lines' => "The sale for parcel {$shipment->tracking_number} is cancelled."]);
                    }

                    if ($collected > round($sale->amountDue(), 2) + 0.009) {
                        throw ValidationException::withMessages(['lines' => "Parcel {$shipment->tracking_number}: Rs ".number_format($collected, 2).' is more than the Rs '.number_format($sale->amountDue(), 2).' still owed on '.$sale->invoiceNumber().'.']);
                    }
                }

                $rows[] = [$shipment, $sale, $collected, $charge, $returnCharge];
            }

            $codTotal = round(array_sum(array_column($rows, 2)), 2);
            $charges = round(array_sum(array_column($rows, 3)) + array_sum(array_column($rows, 4)), 2);
            $tax = round((float) ($payout['tax_withheld'] ?? 0), 2);
            $other = round((float) ($payout['other_deductions'] ?? 0), 2);
            $net = round($codTotal - $charges - $tax - $other, 2);

            if ($tax < 0 || $other < 0) {
                throw ValidationException::withMessages(['taxWithheld' => 'Deductions can\'t be below zero.']);
            }

            if ($net < 0) {
                throw ValidationException::withMessages(['lines' => 'The courier\'s deductions (Rs '.number_format($charges + $tax + $other, 2).') are more than the cash it collected (Rs '.number_format($codTotal, 2).'). Pay what you owe them as an expense instead.']);
            }

            $paidInto = SalePaymentMethod::paymentAttributes($payout['payment_method'] ?? null, $payout['shop_account_id'] ?? null, $shop->id, $net);

            if ($net > 0 && $paidInto['payment_method'] === null) {
                throw ValidationException::withMessages(['paymentMethod' => 'Choose where the money was paid: cash or one of your accounts.']);
            }

            $settlement = CourierSettlement::create([
                'shop_id' => $shop->id,
                'courier' => $courier,
                'reference' => filled($payout['reference'] ?? null) ? mb_substr(trim($payout['reference']), 0, 60) : null,
                'settlement_date' => $date,
                'cod_total' => $codTotal,
                'charges_total' => $charges,
                'tax_withheld' => $tax,
                'other_deductions' => $other,
                'net_amount' => $net,
                'payment_method' => $paidInto['payment_method'],
                'shop_account_id' => $paidInto['shop_account_id'],
                'note' => filled($payout['note'] ?? null) ? mb_substr(trim($payout['note']), 0, 255) : null,
                'status' => RecordStatus::Active,
                'user_id' => $userId,
            ]);

            foreach ($rows as [$shipment, $sale, $collected, $charge, $returnCharge]) {
                $payment = $collected > 0 ? $sale->payments()->create([
                    'shop_id' => $shop->id,
                    'user_id' => $userId,
                    'amount' => $collected,
                    'payment_date' => $date,
                    'payment_method' => SalePaymentMethod::Courier->value,
                    'shop_account_id' => null,
                    'note' => $shipment->courierLabel().' payout '.$settlement->number(),
                ]) : null;

                CourierSettlementItem::create([
                    'shop_id' => $shop->id,
                    'courier_settlement_id' => $settlement->id,
                    'shipment_id' => $shipment->id,
                    'sale_id' => $sale?->id,
                    'cod_collected' => $collected,
                    'courier_charge' => $charge,
                    'return_charge' => $returnCharge,
                    'sale_payment_id' => $payment?->id,
                ]);

                $shipment->update(['courier_settlement_id' => $settlement->id] + ($returnCharge > 0 ? ['return_charge' => $returnCharge] : []));
            }

            return $settlement;
        });
    }

    /** Undoes a payout recorded wrongly: its parcels wait for a payout again. */
    public static function cancel(CourierSettlement $settlement, ?int $userId): void
    {
        DB::transaction(function () use ($settlement, $userId) {
            $settlement = CourierSettlement::withoutGlobalScopes()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            if ($settlement->isCancelled()) {
                throw ValidationException::withMessages(['settlement' => 'This payout is already cancelled.']);
            }

            foreach ($settlement->items()->with('sale')->get() as $item) {
                if ((float) $item->cod_collected > 0 && $item->sale !== null && ! $item->sale->isCancelled()) {
                    $item->sale->payments()->create([
                        'shop_id' => $settlement->shop_id,
                        'user_id' => $userId,
                        'amount' => -(float) $item->cod_collected,
                        'payment_date' => BatchExpiry::today(),
                        'payment_method' => SalePaymentMethod::Courier->value,
                        'shop_account_id' => null,
                        'note' => 'Payout '.$settlement->number().' cancelled',
                    ]);
                }

                Shipment::withoutGlobalScopes()->whereKey($item->shipment_id)->update(['courier_settlement_id' => null]);
            }

            $settlement->update(['status' => RecordStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $userId]);
        });
    }
}
