<?php

namespace App\Services;

use App\Enums\SubscriptionInvoiceStatus;
use App\Models\Shop;
use App\Models\SubscriptionInvoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionBillingService
{
    /**
     * @param  array{period_start: string, months: int, amount: float|string, discount?: float|string|null, notes?: ?string}  $data
     */
    public function create(Shop $shop, array $data, ?int $userId, ?string $paidAt = null): SubscriptionInvoice
    {
        return DB::transaction(function () use ($shop, $data, $userId, $paidAt) {
            $start = Carbon::parse($data['period_start'])->startOfDay();
            $months = (int) $data['months'];
            $amount = (float) $data['amount'];
            $discount = (float) ($data['discount'] ?? 0);
            $paid = $paidAt !== null;

            $invoice = SubscriptionInvoice::create([
                'shop_id' => $shop->id,
                'invoice_number' => $this->nextInvoiceNumber(),
                'plan_type' => $shop->plan_type?->value ?? 'monthly',
                'period_start' => $start->toDateString(),
                'months' => $months,
                'period_end' => SubscriptionInvoice::periodEndFor($start, $months)->toDateString(),
                'amount' => $amount,
                'discount' => $discount,
                'total' => SubscriptionInvoice::totalFor($amount, $discount),
                'status' => $paid ? SubscriptionInvoiceStatus::Paid : SubscriptionInvoiceStatus::Unpaid,
                'paid_at' => $paid ? Carbon::parse($paidAt)->toDateString() : null,
                'notes' => $this->nullable($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            return $invoice;
        });
    }

    /**
     * @param  array{period_start: string, months: int, amount: float|string, discount?: float|string|null, notes?: ?string}  $data
     */
    public function update(SubscriptionInvoice $invoice, array $data): SubscriptionInvoice
    {
        if ($invoice->isCancelled()) {
            throw new \RuntimeException('A cancelled invoice cannot be edited.');
        }

        $start = Carbon::parse($data['period_start'])->startOfDay();
        $months = (int) $data['months'];
        $amount = (float) $data['amount'];
        $discount = (float) ($data['discount'] ?? 0);

        $invoice->update([
            'period_start' => $start->toDateString(),
            'months' => $months,
            'period_end' => SubscriptionInvoice::periodEndFor($start, $months)->toDateString(),
            'amount' => $amount,
            'discount' => $discount,
            'total' => SubscriptionInvoice::totalFor($amount, $discount),
            'notes' => $this->nullable($data['notes'] ?? null),
        ]);

        return $invoice->fresh();
    }

    public function markPaid(SubscriptionInvoice $invoice, ?string $paidAt = null): SubscriptionInvoice
    {
        $this->guardNotCancelled($invoice);

        $invoice->update([
            'status' => SubscriptionInvoiceStatus::Paid,
            'paid_at' => Carbon::parse($paidAt ?? now())->toDateString(),
        ]);

        return $invoice->fresh();
    }

    public function markUnpaid(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        $this->guardNotCancelled($invoice);

        $invoice->update([
            'status' => SubscriptionInvoiceStatus::Unpaid,
            'paid_at' => null,
        ]);

        return $invoice->fresh();
    }

    public function cancel(SubscriptionInvoice $invoice, string $reason): SubscriptionInvoice
    {
        $this->guardNotCancelled($invoice);

        $invoice->update([
            'status' => SubscriptionInvoiceStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        return $invoice->fresh();
    }

    /**
     * A counter row per calendar year, incremented under a row lock inside
     * the caller's transaction. Numbers are only ever moved forward, so a
     * cancelled invoice keeps its number and a rolled-back create leaves no gap
     * behind a number that was actually issued.
     */
    protected function nextInvoiceNumber(): string
    {
        $year = (int) now()->format('Y');

        DB::table('billing_number_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);

        $current = DB::table('billing_number_sequences')->where('year', $year)->lockForUpdate()->value('last_number');
        $next = (int) $current + 1;

        DB::table('billing_number_sequences')->where('year', $year)->update(['last_number' => $next]);

        return sprintf('%s-%d-%04d', config('branding.invoice_prefix'), $year, $next);
    }

    protected function guardNotCancelled(SubscriptionInvoice $invoice): void
    {
        if ($invoice->isCancelled()) {
            throw new \RuntimeException('A cancelled invoice cannot change status.');
        }
    }

    protected function nullable(?string $value): ?string
    {
        $trimmed = $value !== null ? trim($value) : '';

        return $trimmed !== '' ? $trimmed : null;
    }
}
