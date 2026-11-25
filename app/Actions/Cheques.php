<?php

namespace App\Actions;

use App\Enums\StockInPaymentSource;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\ShopAccount;
use App\Models\Vendor;
use App\Models\VendorCredit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cheques received from customers and issued to vendors (W6), most of them
 * post-dated.
 *
 * As at the bank, a cheque moves no money and settles nothing while it is in
 * hand or deposited; it is a promise, listed so it can be banked on its date.
 * When it clears, the money lands in the shop account it went into:
 *   - received: a counter receipt (CustomerPayments::receiveAtCounter) pays
 *     the udhaar balance and then the oldest unpaid bills, the rest kept as
 *     advance. Counter-service dues are left alone, so a bounce can always be
 *     undone exactly.
 *   - issued: the vendor's oldest unpaid stock is paid (one stock-in payment
 *     per batch); anything beyond what's owed stays as credit with them.
 * A bounce before clearing just marks it. A bounce after clearing reverses
 * exactly what it settled, dated the day it bounced: negative sale payments
 * and udhaar repayment reversals for a received cheque (the dues come back),
 * negative stock-in payments for an issued one.
 */
class Cheques
{
    public static function rules(): array
    {
        return [
            'direction' => ['required', 'in:received,issued'],
            'partyId' => ['required', 'integer'],
            'chequeNumber' => ['required', 'string', 'max:40'],
            'bank' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'chequeDate' => ['required', 'date'],
            'receivedOn' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @param  array{direction: string, party_id: int, cheque_number: string, bank: string, amount: float|string, cheque_date: string, received_on: string, note?: ?string, shop_account_id?: ?int}  $data */
    public static function record(array $data, ?int $userId): Cheque
    {
        $received = $data['direction'] === Cheque::RECEIVED;
        $party = $received ? Customer::find($data['party_id']) : Vendor::find($data['party_id']);

        if ($party === null) {
            throw ValidationException::withMessages(['partyId' => $received ? 'Choose the customer who gave it.' : 'Choose the vendor it was given to.']);
        }

        if (! $received && empty($data['shop_account_id'])) {
            throw ValidationException::withMessages(['shopAccountId' => 'Choose the account the cheque is drawn on.']);
        }

        return Cheque::create([
            'direction' => $data['direction'],
            'customer_id' => $received ? $party->id : null,
            'vendor_id' => $received ? null : $party->id,
            'cheque_number' => trim($data['cheque_number']),
            'bank' => trim($data['bank']),
            'amount' => round((float) $data['amount'], 2),
            'cheque_date' => $data['cheque_date'],
            'received_on' => $data['received_on'],
            'status' => Cheque::PENDING,
            'shop_account_id' => $data['shop_account_id'] ?? null,
            'note' => filled($data['note'] ?? null) ? $data['note'] : null,
            'user_id' => $userId,
        ]);
    }

    protected static function lock(Cheque $cheque): Cheque
    {
        return Cheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();
    }

    protected static function account(?int $shopAccountId): ShopAccount
    {
        return ($shopAccountId !== null ? ShopAccount::query()->find($shopAccountId) : null)
            ?? throw ValidationException::withMessages(['shopAccountId' => 'Choose one of your shop accounts.']);
    }

    /** A received cheque taken to the bank. */
    public static function deposit(Cheque $cheque, int $shopAccountId): void
    {
        DB::transaction(function () use ($cheque, $shopAccountId) {
            $cheque = self::lock($cheque);

            if (! $cheque->isReceived() || $cheque->status !== Cheque::PENDING) {
                throw ValidationException::withMessages(['cheque' => 'Only a received cheque in hand can be deposited.']);
            }

            $cheque->update(['status' => Cheque::DEPOSITED, 'shop_account_id' => self::account($shopAccountId)->id, 'deposited_on' => now()->toDateString()]);
        });
    }

    /** The bank paid it: the money settles what it was for. */
    public static function clear(Cheque $cheque, ?int $shopAccountId, ?int $userId): void
    {
        DB::transaction(function () use ($cheque, $shopAccountId, $userId) {
            $cheque = self::lock($cheque);

            if (! $cheque->isOpen()) {
                throw ValidationException::withMessages(['cheque' => 'This cheque is already '.mb_strtolower($cheque->statusLabel()).'.']);
            }

            $account = self::account($shopAccountId ?? $cheque->shop_account_id);
            $note = $cheque->label();

            if ($cheque->isReceived()) {
                $receipt = CustomerPayments::receiveAtCounter($cheque->customer, (float) $cheque->amount, 'account', $account->id, $userId, $note, ['udhaar', 'sale']);
                $cheque->update(['status' => Cheque::CLEARED, 'cleared_on' => now()->toDateString(), 'shop_account_id' => $account->id, 'customer_receipt_id' => $receipt->id]);

                return;
            }

            $applied = VendorCredits::payBatches($cheque->vendor_id, (float) $cheque->amount, StockInPaymentSource::Cheque, 'account', $account->id, $userId, $note);
            $left = round((float) $cheque->amount - array_sum(array_column($applied, 'amount')), 2);
            $credit = $left > 0 ? VendorCredits::issue($cheque->vendor_id, $left, VendorCredit::CHEQUE, $cheque->id, $userId, $note, false) : null;

            $cheque->update([
                'status' => Cheque::CLEARED,
                'cleared_on' => now()->toDateString(),
                'shop_account_id' => $account->id,
                'applied_to' => ['batches' => $applied, 'vendor_credit_id' => $credit?->id],
            ]);
        });
    }

    /** It came back unpaid. After clearing, what it settled is undone. */
    public static function bounce(Cheque $cheque, string $reason, ?int $userId): void
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['bounceReason' => 'Say why it bounced (as the bank wrote it).']);
        }

        DB::transaction(function () use ($cheque, $reason, $userId) {
            $cheque = self::lock($cheque);

            if (! $cheque->isOpen() && $cheque->status !== Cheque::CLEARED) {
                throw ValidationException::withMessages(['cheque' => 'This cheque is already '.mb_strtolower($cheque->statusLabel()).'.']);
            }

            $note = $cheque->label().' bounced';

            if ($cheque->status === Cheque::CLEARED && $cheque->isReceived() && $cheque->receipt) {
                CustomerPayments::reverseReceipt($cheque->receipt, $note, $userId);
            }

            if ($cheque->status === Cheque::CLEARED && ! $cheque->isReceived()) {
                $creditId = $cheque->applied_to['vendor_credit_id'] ?? null;

                if ($creditId !== null && ($credit = VendorCredit::find($creditId)) !== null) {
                    if (round((float) $credit->remaining, 2) < round((float) $credit->amount, 2)) {
                        throw ValidationException::withMessages(['cheque' => 'Part of what this cheque overpaid has already been used, so undo that first.']);
                    }

                    $credit->update(['status' => 'cancelled', 'remaining' => 0]);
                }

                VendorCredits::unpayBatches($cheque->applied_to['batches'] ?? [], StockInPaymentSource::Cheque, 'account', $cheque->shop_account_id, $userId, $note);
            }

            $cheque->update(['status' => Cheque::BOUNCED, 'bounced_on' => now()->toDateString(), 'bounce_reason' => mb_substr($reason, 0, 255)]);
        });
    }

    /** Recorded by mistake, or handed back: only while it settled nothing. */
    public static function cancel(Cheque $cheque): void
    {
        DB::transaction(function () use ($cheque) {
            $cheque = self::lock($cheque);

            if (! $cheque->isOpen()) {
                throw ValidationException::withMessages(['cheque' => 'Only a cheque in hand or deposited can be cancelled.']);
            }

            $cheque->update(['status' => Cheque::CANCELLED]);
        });
    }

    /** Received cheques not yet cleared, per customer (or one customer). */
    public static function inHand(?int $customerId = null): float
    {
        return round((float) Cheque::query()
            ->where('direction', Cheque::RECEIVED)
            ->whereIn('status', [Cheque::PENDING, Cheque::DEPOSITED])
            ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
            ->sum('amount'), 2);
    }
}
