<?php

namespace App\Support;

use App\Models\Sale;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Where a sale's instalment plan stands. Nothing here is stored: payments live
 * in sale_payments as for any partly paid sale, so this works backwards from
 * what is still owed. Instalments are paid off in due-date order, so with
 * Rs 15,000 owed on a 3 x Rs 10,000 plan, the first is paid, the second half
 * paid and the third untouched.
 *
 * If the sale is edited to a higher total, the extra isn't on any date and
 * counts as due now ("unscheduled"). A lower total simply pays off the
 * earliest instalments.
 */
class InstallmentSchedule
{
    public const MAX_COUNT = 36;

    /** @var Collection<int, array{sequence: int, due_date: CarbonImmutable, amount: float, paid: float, left: float, status: string}> */
    protected Collection $rows;

    protected float $unscheduled;

    public function __construct(public readonly Sale $sale, ?CarbonInterface $today = null)
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());
        $installments = $sale->relationLoaded('installments') ? $sale->installments : $sale->installments()->get();
        $amountDue = round($sale->amountDue(), 2);
        $scheduled = round((float) $installments->sum('amount'), 2);

        $this->unscheduled = round(max(0.0, $amountDue - $scheduled), 2);
        $covered = round(max(0.0, $scheduled - $amountDue), 2);

        $this->rows = $installments->sortBy('sequence')->values()->map(function ($installment) use (&$covered, $today) {
            $amount = (float) $installment->amount;
            $paid = round(min($amount, $covered), 2);
            $covered = round($covered - $paid, 2);
            $left = round($amount - $paid, 2);
            $due = CarbonImmutable::parse($installment->due_date->toDateString());

            return [
                'sequence' => $installment->sequence,
                'due_date' => $due,
                'amount' => $amount,
                'paid' => $paid,
                'left' => $left,
                'status' => match (true) {
                    $left <= 0 => 'paid',
                    $due->lt($today) => 'overdue',
                    $due->eq($today) => 'due_today',
                    default => 'upcoming',
                },
            ];
        });
    }

    /** Null for a sale that isn't on instalments. */
    public static function for(Sale $sale, ?CarbonInterface $today = null): ?self
    {
        $hasPlan = $sale->relationLoaded('installments') ? $sale->installments->isNotEmpty() : $sale->installments()->exists();

        return $hasPlan ? new self($sale, $today) : null;
    }

    public function rows(): Collection
    {
        return $this->rows;
    }

    public function count(): int
    {
        return $this->rows->count();
    }

    public function overdueAmount(): float
    {
        return round((float) $this->rows->where('status', 'overdue')->sum('left'), 2);
    }

    /** Overdue, due today, and anything not on a date. What a reminder asks for. */
    public function dueNowAmount(): float
    {
        return round((float) $this->rows->whereIn('status', ['overdue', 'due_today'])->sum('left') + $this->unscheduled, 2);
    }

    public function unscheduledAmount(): float
    {
        return $this->unscheduled;
    }

    /** The earliest instalment with anything left on it. */
    public function next(): ?array
    {
        return $this->rows->first(fn (array $row) => $row['left'] > 0);
    }

    /** The next one still to fall due (after today). */
    public function nextUpcoming(): ?array
    {
        return $this->rows->first(fn (array $row) => $row['status'] === 'upcoming');
    }

    public function oldestOverdue(): ?array
    {
        return $this->rows->first(fn (array $row) => $row['status'] === 'overdue');
    }

    public function isComplete(): bool
    {
        return $this->next() === null && $this->unscheduled <= 0;
    }

    /** One line for lists: what is late, else what comes next. */
    public function summary(): string
    {
        if ($this->isComplete()) {
            return 'All instalments paid';
        }

        $parts = [];

        if (($overdue = $this->oldestOverdue()) !== null) {
            $parts[] = 'Rs '.number_format($this->overdueAmount(), 0).' overdue since '.$overdue['due_date']->format('d M');
        }

        if (($today = $this->rows->firstWhere('status', 'due_today')) !== null) {
            $parts[] = 'Rs '.number_format($today['left'], 0).' due today';
        }

        if ($this->unscheduled > 0) {
            $parts[] = 'Rs '.number_format($this->unscheduled, 0).' not on the plan, due now';
        }

        if (($upcoming = $this->nextUpcoming()) !== null) {
            $parts[] = 'next Rs '.number_format($upcoming['left'], 0).' on '.$upcoming['due_date']->format('d M Y');
        }

        return ucfirst(implode(' · ', $parts));
    }

    /**
     * An even monthly plan for $remaining: equal whole-rupee instalments, with
     * the rounding left on the last one so the plan adds up exactly.
     *
     * @return list<array{sequence: int, due_date: string, amount: float}>
     */
    public static function plan(float $remaining, int $count, CarbonInterface $firstDue): array
    {
        $remaining = round($remaining, 2);

        if ($count < 1 || $count > self::MAX_COUNT) {
            throw ValidationException::withMessages(['installmentCount' => 'Choose between 1 and '.self::MAX_COUNT.' instalments.']);
        }

        if ($remaining <= 0) {
            throw ValidationException::withMessages(['installmentCount' => 'Nothing is left to pay in instalments after the down payment.']);
        }

        $each = floor($remaining / $count);

        if ($each < 1) {
            throw ValidationException::withMessages(['installmentCount' => 'That is too many instalments for Rs '.number_format($remaining, 2).'.']);
        }

        $first = CarbonImmutable::parse($firstDue->toDateString());
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'sequence' => $i + 1,
                'due_date' => $first->addMonthsNoOverflow($i)->toDateString(),
                'amount' => $i === $count - 1 ? round($remaining - $each * ($count - 1), 2) : (float) $each,
            ];
        }

        return $rows;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Paid',
            'overdue' => 'Overdue',
            'due_today' => 'Due today',
            default => 'Upcoming',
        };
    }

    public static function statusVariant(string $status): string
    {
        return match ($status) {
            'paid' => 'success',
            'overdue' => 'danger',
            'due_today' => 'warning',
            default => 'neutral',
        };
    }
}
