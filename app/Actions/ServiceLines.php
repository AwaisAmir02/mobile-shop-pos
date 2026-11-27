<?php

namespace App\Actions;

use App\Enums\MembershipKind;
use App\Enums\RecordStatus;
use App\Enums\SaleLineKind;
use App\Enums\SalePaymentMethod;
use App\Models\Employee;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceItem;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The lines on a sale that aren't goods (the Services group): a service off
 * the menu or typed in (labour), a membership or package bought, and a session
 * taken from a package bought before. The one way they get onto a sale is
 * CreateSale(services: ...), which asks price() before saving and record()
 * after; nothing here touches stock.
 *
 * A line's figures are stored the way a product line's are (unit_price,
 * discount_amount, line_total), with cost 0, so every total, return, report and
 * the ledger read it with no special case. product_type carries the kind, so a
 * category breakdown can show "Services" beside the product categories.
 *
 * Input line: kind (service|membership|package), service_item_id, membership_plan_id,
 * membership_id (package), name, unit_price, quantity, discount, performed_by.
 */
class ServiceLines
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{items: list<array<string, mixed>>, subtotal: float}
     */
    public static function price(array $lines, int $shopId, ?int $customerId, string $saleDate): array
    {
        $items = [];
        $subtotal = 0.0;
        $staff = Employee::withoutGlobalScopes()->where('shop_id', $shopId)->pluck('id')->all();

        foreach (array_values($lines) as $i => $line) {
            $key = "services.{$i}";
            $kind = SaleLineKind::tryFrom((string) ($line['kind'] ?? ''));
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $performer = ! empty($line['performed_by']) ? (int) $line['performed_by'] : null;

            if ($performer !== null && ! in_array($performer, $staff, true)) {
                throw ValidationException::withMessages([$key => 'Choose one of your staff for who did it.']);
            }

            $row = match ($kind) {
                SaleLineKind::Service => self::serviceRow($line, $shopId, $key),
                SaleLineKind::Membership => self::membershipRow($line, $shopId, $customerId, $key, $quantity),
                SaleLineKind::Package => self::packageRow($line, $shopId, $customerId, $saleDate, $key, $quantity),
                default => throw ValidationException::withMessages([$key => 'That line isn\'t a service, a membership or a package session.']),
            };

            $discount = $kind === SaleLineKind::Package ? 0.0 : round(max(0.0, (float) ($line['discount'] ?? 0)), 2);
            $gross = Quantity::money($row['unit_price'], $quantity);

            if ($discount > $gross) {
                throw ValidationException::withMessages([$key => 'The discount on '.$row['product_name'].' is more than the line.']);
            }

            $lineTotal = round($gross - $discount, 2);
            $subtotal += $lineTotal;

            $items[] = $row + [
                'product_id' => null,
                'stock_in_id' => null,
                'product_type' => $kind->value,
                'line_kind' => $kind->value,
                'performed_by' => $performer,
                'list_price' => $row['unit_price'],
                'cost_price' => 0,
                'quantity' => $quantity,
                'unit' => 'piece',
                'discount_amount' => $discount,
                'line_total' => $lineTotal,
            ];
        }

        return ['items' => $items, 'subtotal' => round($subtotal, 2)];
    }

    /** After the sale and its lines are saved: memberships bought on it start. */
    public static function record(Sale $sale, string $saleDate): void
    {
        $items = SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->where('line_kind', SaleLineKind::Membership->value)->orderBy('id')->get();

        foreach ($items as $item) {
            $membership = Memberships::fromSaleItem($sale, $item, Carbon::parse($saleDate));
            $item->forceFill(['membership_id' => $membership->id])->save();
        }
    }

    /**
     * Before CancelSale goes ahead: memberships it sold are cancelled with it,
     * unless sessions of one were already taken on another bill. Gift vouchers
     * and deposits it used come back by themselves (what they have left is
     * worked out from sales that stand). A booking or job card it billed goes
     * back to being open.
     */
    public static function cancelWith(Sale $sale): void
    {
        foreach (Membership::withoutGlobalScopes()->where('sale_id', $sale->id)->where('status', RecordStatus::Active->value)->get() as $membership) {
            $usedElsewhere = $membership->kind === MembershipKind::Sessions && SaleItem::withoutGlobalScopes()
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sale_items.membership_id', $membership->id)
                ->where('sale_items.line_kind', SaleLineKind::Package->value)
                ->where('sales.status', '!=', 'cancelled')
                ->where('sales.id', '!=', $sale->id)
                ->exists();

            if ($usedElsewhere) {
                throw ValidationException::withMessages(['cancelReason' => 'Sessions of the '.$membership->plan_name.' package sold on this bill were already used on other bills. Cancel those first.']);
            }

            $membership->forceFill(['status' => RecordStatus::Cancelled, 'cancelled_at' => now()])->save();
        }

        Appointments::billCancelled($sale);
        JobCards::billCancelled($sale);
    }

    /** Whether the sale has any line that isn't goods. */
    public static function on(Sale $sale): bool
    {
        return SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->where('line_kind', '!=', SaleLineKind::Product->value)->exists();
    }

    /**
     * Gift vouchers and deposits paying part of the sale, after its own payment.
     *
     * @param  list<array{method: string, id: int, amount: float|string}>  $prepaid
     */
    public static function applyPrepaid(Sale $sale, array $prepaid, float $alreadyPaid, ?int $userId, string $saleDate): float
    {
        $total = 0.0;

        foreach ($prepaid as $part) {
            $amount = round((float) ($part['amount'] ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            if (round($alreadyPaid + $total + $amount, 2) > round((float) $sale->total, 2)) {
                throw ValidationException::withMessages(['prepaid' => 'Vouchers, deposits and the amount paid add up to more than the bill (Rs '.number_format((float) $sale->total, 2).').']);
            }

            match (SalePaymentMethod::tryFrom((string) ($part['method'] ?? ''))) {
                SalePaymentMethod::GiftVoucher => GiftVouchers::spend($sale, (int) $part['id'], $amount, $userId, $saleDate),
                SalePaymentMethod::Deposit => CustomerDeposits::spend($sale, (int) $part['id'], $amount, $userId),
                default => throw ValidationException::withMessages(['prepaid' => 'Only a gift voucher or a deposit can pay that way.']),
            };

            $total += $amount;
        }

        return round($total, 2);
    }

    protected static function serviceRow(array $line, int $shopId, string $key): array
    {
        $item = ! empty($line['service_item_id'])
            ? ServiceItem::withoutGlobalScopes()->where('shop_id', $shopId)->find((int) $line['service_item_id'])
            : null;

        if (! empty($line['service_item_id']) && $item === null) {
            throw ValidationException::withMessages([$key => 'That service isn\'t on your menu.']);
        }

        $name = trim((string) ($line['name'] ?? '')) !== '' ? mb_substr(trim((string) $line['name']), 0, 255) : $item?->name;

        if ($name === null || $name === '') {
            throw ValidationException::withMessages([$key => 'Say what the service or labour was.']);
        }

        $price = isset($line['unit_price']) && $line['unit_price'] !== '' && $line['unit_price'] !== null ? (float) $line['unit_price'] : (float) ($item?->price ?? 0);

        if ($price < 0) {
            throw ValidationException::withMessages([$key => 'A price can\'t be below zero.']);
        }

        return ['product_name' => $name, 'service_item_id' => $item?->id, 'membership_plan_id' => null, 'membership_id' => null, 'unit_price' => round($price, 2)];
    }

    protected static function membershipRow(array $line, int $shopId, ?int $customerId, string $key, int $quantity): array
    {
        $plan = MembershipPlan::withoutGlobalScopes()->where('shop_id', $shopId)->find((int) ($line['membership_plan_id'] ?? 0));

        if ($plan === null || ! $plan->is_active) {
            throw ValidationException::withMessages([$key => 'That membership or package isn\'t on sale any more.']);
        }

        if ($customerId === null) {
            throw ValidationException::withMessages(['customerId' => 'Choose the customer the '.$plan->name.' is for.']);
        }

        if ($quantity !== 1) {
            throw ValidationException::withMessages([$key => 'Sell one '.$plan->name.' at a time.']);
        }

        $price = isset($line['unit_price']) && $line['unit_price'] !== '' && $line['unit_price'] !== null ? (float) $line['unit_price'] : (float) $plan->price;

        return ['product_name' => mb_substr($plan->name, 0, 255), 'service_item_id' => null, 'membership_plan_id' => $plan->id, 'membership_id' => null, 'unit_price' => round(max(0.0, $price), 2)];
    }

    protected static function packageRow(array $line, int $shopId, ?int $customerId, string $saleDate, string $key, int $quantity): array
    {
        $membership = Membership::withoutGlobalScopes()->where('shop_id', $shopId)->lockForUpdate()->find((int) ($line['membership_id'] ?? 0));
        $serviceId = ! empty($line['service_item_id']) ? (int) $line['service_item_id'] : null;
        $service = $serviceId !== null ? ServiceItem::withoutGlobalScopes()->where('shop_id', $shopId)->find($serviceId) : null;

        if ($membership === null || $membership->kind !== MembershipKind::Sessions || $membership->isCancelled()) {
            throw ValidationException::withMessages([$key => 'That package can\'t be used.']);
        }

        if ($customerId === null || $membership->customer_id !== $customerId) {
            throw ValidationException::withMessages([$key => 'That package belongs to another customer.']);
        }

        if (! $membership->covers($serviceId)) {
            throw ValidationException::withMessages([$key => 'The '.$membership->plan_name.' package doesn\'t cover '.($service?->name ?? 'this service').'.']);
        }

        if ($membership->ends_on !== null && $membership->ends_on->toDateString() < Carbon::parse($saleDate)->toDateString()) {
            throw ValidationException::withMessages([$key => 'The '.$membership->plan_name.' package ran out on '.$membership->ends_on->format('d M Y').'.']);
        }

        if ($membership->sessionsLeft() < $quantity) {
            throw ValidationException::withMessages([$key => 'Only '.$membership->sessionsLeft().' '.str('session')->plural($membership->sessionsLeft()).' left on the '.$membership->plan_name.' package.']);
        }

        $name = ($service?->name ?? trim((string) ($line['name'] ?? 'Session'))).' (from '.$membership->plan_name.')';

        return ['product_name' => mb_substr($name, 0, 255), 'service_item_id' => $service?->id, 'membership_plan_id' => $membership->membership_plan_id, 'membership_id' => $membership->id, 'unit_price' => 0.0];
    }
}
