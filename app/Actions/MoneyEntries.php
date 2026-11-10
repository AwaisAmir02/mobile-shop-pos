<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Models\MoneyEntry;
use App\Support\MoneyFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes the three things Cash & Balances keeps for itself. A side is a
 * bucket key from MoneyFlow: "cash:{branch}" or "account:{id}".
 */
class MoneyEntries
{
    public static function opening(int $shopId, string $bucket, float $amount, string $date, ?int $userId, ?string $note = null): MoneyEntry
    {
        self::checkDate($date);

        return DB::transaction(function () use ($shopId, $bucket, $amount, $date, $userId, $note) {
            if ((new MoneyFlow($shopId))->openings()->has($bucket)) {
                throw ValidationException::withMessages(['openingAmount' => 'This already has an opening balance. Cancel it first, or record a count to correct the balance.']);
            }

            return MoneyEntry::create(['shop_id' => $shopId, 'user_id' => $userId, 'kind' => MoneyEntry::OPENING, 'entry_date' => $date, 'amount' => round($amount, 2), 'note' => $note]
                + self::side('to', $bucket));
        });
    }

    public static function transfer(int $shopId, string $from, string $to, float $amount, string $date, ?int $userId, ?string $note = null): MoneyEntry
    {
        self::checkDate($date);

        if ($from === $to) {
            throw ValidationException::withMessages(['moveTo' => 'Pick two different places.']);
        }

        if (round($amount, 2) <= 0) {
            throw ValidationException::withMessages(['moveAmount' => 'Enter an amount above zero.']);
        }

        // Moving cash between two branches would need two branches on one entry; not supported.
        if (str_starts_with($from, 'cash:') && str_starts_with($to, 'cash:')) {
            throw ValidationException::withMessages(['moveTo' => 'Moving cash between branches isn\'t supported yet. Record it as two counts.']);
        }

        $fromSide = self::side('from', $from);
        $toSide = self::side('to', $to);

        return MoneyEntry::create(['shop_id' => $shopId, 'user_id' => $userId, 'kind' => MoneyEntry::TRANSFER, 'entry_date' => $date, 'amount' => round($amount, 2), 'note' => $note]
            + $fromSide + $toSide + ['branch_id' => $fromSide['branch_id'] ?? $toSide['branch_id'] ?? null]);
    }

    /** Records a counted balance; the difference from the expected balance becomes a correction. */
    public static function count(int $shopId, string $bucket, float $counted, string $date, ?int $userId, ?string $note = null): MoneyEntry
    {
        self::checkDate($date);

        return DB::transaction(function () use ($shopId, $bucket, $counted, $date, $userId, $note) {
            $expected = (new MoneyFlow($shopId))->balanceOf($bucket, $date);

            return MoneyEntry::create([
                'shop_id' => $shopId,
                'user_id' => $userId,
                'kind' => MoneyEntry::COUNT,
                'entry_date' => $date,
                'counted' => round($counted, 2),
                'expected' => round($expected, 2),
                'amount' => round($counted - $expected, 2),
                'note' => $note,
            ] + self::side('to', $bucket));
        });
    }

    public static function cancel(MoneyEntry $entry, ?int $userId, ?string $reason = null): void
    {
        $entry->update([
            'status' => RecordStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $userId,
            'cancel_reason' => $reason,
        ]);
    }

    /** @return array<string, mixed> the columns for one side of an entry */
    private static function side(string $side, string $bucket): array
    {
        [$kind, $id] = array_pad(explode(':', $bucket, 2), 2, null);

        return match ($kind) {
            'cash' => [$side.'_method' => 'cash', 'branch_id' => (int) $id],
            'account' => [$side.'_method' => 'account', $side.'_shop_account_id' => (int) $id],
            default => throw ValidationException::withMessages([$side === 'from' ? 'moveFrom' : 'moveTo' => 'Pick cash or one of your accounts.']),
        };
    }

    private static function checkDate(string $date): void
    {
        if ($date > MoneyFlow::today()) {
            throw ValidationException::withMessages(['entryDate' => 'The date can\'t be in the future.']);
        }
    }
}
