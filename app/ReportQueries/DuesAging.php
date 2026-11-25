<?php

namespace App\ReportQueries;

use App\Models\Customer;
use App\Models\Scopes\BranchScope;
use App\Models\UdhaarTransaction;
use App\Support\BatchExpiry;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * How long each customer's money has been owed (W5): 0-30, 31-60, 61-90 and
 * over 90 days, on a given day.
 *
 * Two figures per customer, never netted together:
 *   - bills: unpaid sales and counter services, read from exactly the records
 *     the Udhaar list adds up (UdhaarReport::dueRecords), each aged from its
 *     own date. A sale with a due date that has passed also counts as overdue.
 *   - udhaar: the loan balance, when it is owed. Repayments pay off the oldest
 *     loans first (as CustomerPayments does), and what is left is aged from the
 *     day each loan was given. A reversal cancels the entry it reverses.
 *
 * Shop-wide, like every balance: a customer's dues never depend on the branch.
 */
class DuesAging
{
    public const BUCKETS = ['0–30 days', '31–60 days', '61–90 days', '90+ days'];

    public function __construct(public readonly string $asOf)
    {
    }

    public static function today(): self
    {
        return new self(BatchExpiry::today());
    }

    public static function bucketFor(int $days): int
    {
        return match (true) {
            $days <= 30 => 0,
            $days <= 60 => 1,
            $days <= 90 => 2,
            default => 3,
        };
    }

    /**
     * Every customer who owes anything, most owed first.
     *
     * @return Collection<int, array{customer: Customer, bills: array, udhaar: array, total: float}>
     */
    public function rows(?int $customerId = null): Collection
    {
        return BranchContext::current()->shopWide(fn () => $this->build($customerId));
    }

    /** One customer's figures (zeros when they owe nothing). */
    public function forCustomer(Customer $customer): array
    {
        return $this->rows($customer->id)->first() ?? [
            'customer' => $customer,
            'bills' => self::blank(),
            'udhaar' => self::blank(),
            'total' => 0.0,
        ];
    }

    /** @return array{total: float, buckets: array<int, float>, overdue: float, oldest: ?string} */
    public static function blank(): array
    {
        return ['total' => 0.0, 'buckets' => [0.0, 0.0, 0.0, 0.0], 'overdue' => 0.0, 'oldest' => null];
    }

    /**
     * Column totals over rows().
     *
     * @return array{bills: array, udhaar: array}
     */
    public static function totals(Collection $rows): array
    {
        $sum = function (string $part) use ($rows) {
            $out = self::blank();

            foreach ($rows as $row) {
                $out['total'] += $row[$part]['total'];
                $out['overdue'] += $row[$part]['overdue'];

                foreach ($row[$part]['buckets'] as $i => $amount) {
                    $out['buckets'][$i] += $amount;
                }
            }

            $out['total'] = round($out['total'], 2);
            $out['overdue'] = round($out['overdue'], 2);
            $out['buckets'] = array_map(fn ($v) => round($v, 2), $out['buckets']);

            return $out;
        };

        return ['bills' => $sum('bills'), 'udhaar' => $sum('udhaar')];
    }

    protected function build(?int $customerId): Collection
    {
        $asOf = Carbon::parse($this->asOf)->startOfDay();
        $bills = [];

        foreach ((new UdhaarReport)->dueRecords($customerId) as $record) {
            $bills[$record['customer_id']] ??= self::blank();
            $this->add($bills[$record['customer_id']], $record['amount'], $record['date'], $asOf, $record['due_date'] ?? null);
        }

        $udhaar = [];

        foreach ($this->udhaarLots($customerId) as $id => $lots) {
            foreach ($lots as [$date, $amount]) {
                $udhaar[$id] ??= self::blank();
                $this->add($udhaar[$id], $amount, $date, $asOf, null);
            }
        }

        $ids = array_values(array_unique(array_merge(array_keys($bills), array_keys($udhaar))));

        if ($ids === []) {
            return collect();
        }

        $round = function (array $part) {
            $part['total'] = round($part['total'], 2);
            $part['overdue'] = round($part['overdue'], 2);
            $part['buckets'] = array_map(fn ($v) => round($v, 2), $part['buckets']);

            return $part;
        };

        return Customer::query()->whereIn('id', $ids)->get()
            ->map(function (Customer $customer) use ($bills, $udhaar, $round) {
                $b = $round($bills[$customer->id] ?? self::blank());
                $u = $round($udhaar[$customer->id] ?? self::blank());

                return ['customer' => $customer, 'bills' => $b, 'udhaar' => $u, 'total' => round($b['total'] + $u['total'], 2)];
            })
            ->filter(fn (array $row) => $row['total'] > 0)
            ->sortBy([['total', 'desc'], [fn (array $row) => $row['customer']->name, 'asc']])
            ->values();
    }

    protected function add(array &$part, float $amount, $date, Carbon $asOf, $dueDate): void
    {
        $day = Carbon::parse($date)->startOfDay();
        $days = max(0, (int) $day->diffInDays($asOf, false));

        $part['total'] += $amount;
        $part['buckets'][self::bucketFor($days)] += $amount;

        if ($dueDate !== null && Carbon::parse($dueDate)->startOfDay()->lt($asOf)) {
            $part['overdue'] += $amount;
        }

        $dateString = $day->toDateString();

        if ($part['oldest'] === null || $dateString < $part['oldest']) {
            $part['oldest'] = $dateString;
        }
    }

    /**
     * The udhaar loans still unpaid, per customer owing a balance: [[date, amount], …].
     *
     * @return array<int, list<array{0: string, 1: float}>>
     */
    protected function udhaarLots(?int $customerId): array
    {
        $owing = UdhaarTransaction::balancesByCustomer()
            ->filter(fn (float $balance, $id) => round($balance, 2) > 0 && ($customerId === null || (int) $id === $customerId))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($owing === []) {
            return [];
        }

        $transactions = UdhaarTransaction::withoutGlobalScope(BranchScope::class)
            ->whereIn('customer_id', $owing)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->groupBy('customer_id');

        $lots = [];

        foreach ($transactions as $id => $entries) {
            // A reversal and the entry it reverses cancel each other out.
            $reversed = $entries->pluck('reverses_transaction_id')->filter()->map(fn ($id) => (int) $id)->all();
            $queue = [];
            $credit = 0.0;

            foreach ($entries as $entry) {
                if (in_array((int) $entry->id, $reversed, true) || ($entry->reverses_transaction_id !== null && $entries->contains(fn ($other) => (int) $other->id === (int) $entry->reverses_transaction_id))) {
                    continue;
                }

                $amount = round($entry->signedAmount(), 2);
                $date = Carbon::parse($entry->transaction_date)->toDateString();

                if ($amount > 0) {
                    $take = min($credit, $amount);
                    $credit = round($credit - $take, 2);
                    $amount = round($amount - $take, 2);

                    if ($amount > 0) {
                        $queue[] = [$date, $amount];
                    }

                    continue;
                }

                $pay = -$amount;

                while ($pay > 0 && $queue !== []) {
                    $take = min($pay, $queue[0][1]);
                    $queue[0][1] = round($queue[0][1] - $take, 2);
                    $pay = round($pay - $take, 2);

                    if ($queue[0][1] <= 0) {
                        array_shift($queue);
                    }
                }

                $credit = round($credit + $pay, 2);
            }

            if ($queue !== []) {
                $lots[(int) $id] = $queue;
            }
        }

        return $lots;
    }
}
