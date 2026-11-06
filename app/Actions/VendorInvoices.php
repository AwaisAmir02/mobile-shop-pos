<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Enums\StockInPaymentSource;
use App\Enums\VendorInvoiceStatus;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockIn;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceLine;
use App\Support\BranchContext;
use App\Support\Quantity;
use App\Support\VendorInvoiceMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything that happens to a vendor's bill. It creates no payable: what's owed
 * arose when the goods were received, on stock_ins, and only approve() and
 * cancel() ever change those rows. These don't go through UpdateStockIn, which
 * can't hold a billed total and doesn't touch payment status.
 */
class VendorInvoices
{
    /**
     * Within tolerance it's approved on the spot and nothing moves; otherwise it
     * waits for review.
     *
     * @param  array{vendor_id: int, invoice_number: string, invoice_date: string, note?: ?string}  $fields
     * @param  array<int, array{grn_line_id: int, billed_quantity: mixed, billed_amount: mixed}>  $lines
     * @param  array<int, array{label: string, amount: mixed}>  $charges
     */
    public static function record(array $fields, array $lines, array $charges, ?int $userId): VendorInvoice
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Choose at least one received item.']);
        }

        return DB::transaction(function () use ($fields, $lines, $charges, $userId) {
            $restricted = BranchContext::current()->restrictedBranchId();
            $number = trim($fields['invoice_number']);

            $rows = StockIn::whereIn('grn_line_id', array_column($lines, 'grn_line_id'))
                ->where('vendor_id', $fields['vendor_id'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('grn_line_id');

            $shopId = $rows->first()?->shop_id;

            if ($shopId === null || $rows->count() !== count($lines)) {
                throw ValidationException::withMessages(['lines' => 'A line isn\'t an active receipt from this vendor.']);
            }

            SavePurchaseOrder::assertEnabled($shopId);

            if (VendorInvoiceLine::whereIn('grn_line_id', $rows->keys())->whereNotNull('active_marker')->exists()) {
                throw ValidationException::withMessages(['lines' => 'A received item is already on another invoice. Cancel that invoice first.']);
            }

            if (VendorInvoice::where('vendor_id', $fields['vendor_id'])->where('invoice_number', $number)->whereNotNull('active_marker')->exists()) {
                throw ValidationException::withMessages(['invoiceNumber' => "This vendor already has an invoice numbered {$number}."]);
            }

            $invoice = new VendorInvoice([
                'vendor_id' => $fields['vendor_id'],
                'user_id' => $userId,
                'invoice_number' => $number,
                'invoice_date' => $fields['invoice_date'],
                'note' => filled($fields['note'] ?? null) ? $fields['note'] : null,
            ]);
            $invoice->shop_id = $shopId;
            $invoice->save();

            foreach ($lines as $input) {
                $row = $rows->get($input['grn_line_id']);

                if ($restricted && (int) $row->branch_id !== (int) $restricted) {
                    throw ValidationException::withMessages(['lines' => "{$row->product_name} was received at another branch."]);
                }

                if ((float) $input['billed_quantity'] <= 0) {
                    throw ValidationException::withMessages(['lines' => "Enter the quantity billed for {$row->product_name}."]);
                }

                $row->unit->assertQuantity($input['billed_quantity'], 'lines', $row->product_name);

                $invoice->lines()->create([
                    'shop_id' => $shopId,
                    'grn_line_id' => $input['grn_line_id'],
                    'stock_in_id' => $row->id,
                    'billed_quantity' => Quantity::normalize($input['billed_quantity']),
                    'billed_amount' => round((float) $input['billed_amount'], 2),
                ]);
            }

            foreach ($charges as $charge) {
                $invoice->charges()->create(['shop_id' => $shopId, 'label' => trim($charge['label']), 'amount' => round((float) $charge['amount'], 2)]);
            }

            $result = self::evaluate($invoice);

            if ($result['passes']) {
                $invoice->fill([
                    'status' => VendorInvoiceStatus::Approved,
                    'auto_passed' => true,
                    'reviewed_at' => now(),
                ])->save();
            }

            return $invoice->fresh(['lines', 'charges']);
        });
    }

    /**
     * Corrects the batches to what was billed, all or nothing. $reviewed is the
     * fingerprint of the figures the approver saw: if they've changed since, they
     * look again rather than approve something they didn't see.
     */
    public static function approve(VendorInvoice $invoice, string $reason, ?int $userId, ?string $reviewed = null): VendorInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $userId, $reviewed) {
            $invoice = self::lock($invoice);

            SavePurchaseOrder::assertEnabled($invoice->shop_id);
            self::assertBranch($invoice);

            if (! $invoice->isFlagged()) {
                throw ValidationException::withMessages(['invoice' => 'Only an invoice waiting for review can be approved.']);
            }

            if (trim($reason) === '') {
                throw ValidationException::withMessages(['reviewNote' => 'Say why you\'re approving these differences.']);
            }

            $result = VendorInvoiceMatch::evaluate($invoice->lines, $invoice->charges);

            if ($reviewed !== null && $reviewed !== $result['fingerprint']) {
                throw ValidationException::withMessages(['invoice' => 'The received costs changed while you were reviewing. Check the new figures and approve again.']);
            }

            if (! $result['can_allocate_charges']) {
                throw ValidationException::withMessages(['invoice' => 'Delivery, round-off and discounts can\'t be shared across lines with no value. Dispute or cancel this invoice.']);
            }

            foreach ($result['lines'] as $entry) {
                $row = $entry['line']->stockIn;

                if ($entry['changes'] && $entry['new_total'] < 0) {
                    throw ValidationException::withMessages(['invoice' => "The discount takes {$row->product_name} below zero. Dispute this invoice instead."]);
                }

                if ($entry['changes'] && $entry['new_total'] < (float) $row->amount_paid) {
                    throw ValidationException::withMessages(['invoice' => "{$row->product_name} would cost Rs ".number_format($entry['new_total'], 2).', but Rs '.number_format((float) $row->amount_paid, 2).' is already paid against it. There\'s no vendor credit yet, so dispute this invoice instead.']);
                }
            }

            foreach ($result['lines'] as $entry) {
                if ($entry['changes']) {
                    self::correctRow($entry['line'], $entry['new_total'], $reason, $userId);
                }
            }

            $invoice->fill([
                'status' => VendorInvoiceStatus::Approved,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'review_note' => trim($reason),
            ])->save();

            return $invoice->fresh(['lines', 'charges']);
        });
    }

    /** Changes nothing on stock_ins: dues stay at the received figures. Holds its lines and number until cancelled. */
    public static function dispute(VendorInvoice $invoice, string $reason, ?int $userId): VendorInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $userId) {
            $invoice = self::lock($invoice);
            self::assertBranch($invoice);

            if (! $invoice->isFlagged()) {
                throw ValidationException::withMessages(['invoice' => 'Only an invoice waiting for review can be disputed.']);
            }

            if (trim($reason) === '') {
                throw ValidationException::withMessages(['reviewNote' => 'Say what you\'re disputing.']);
            }

            $invoice->fill([
                'status' => VendorInvoiceStatus::Disputed,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'review_note' => trim($reason),
            ])->save();

            return $invoice;
        });
    }

    /** Frees the lines and number. An approved one puts its batches back as they were. */
    public static function cancel(VendorInvoice $invoice, ?int $userId, ?string $reason = null): VendorInvoice
    {
        return DB::transaction(function () use ($invoice, $userId, $reason) {
            $invoice = self::lock($invoice);
            self::assertBranch($invoice);

            if ($invoice->isCancelled()) {
                throw new \RuntimeException('This invoice has already been cancelled.');
            }

            $amended = $invoice->lines->where('amended', true);

            foreach ($amended as $line) {
                if ((float) $line->stockIn->amount_paid > (float) ($line->previous_total_cost ?? 0)) {
                    throw ValidationException::withMessages(['invoice' => "Rs ".number_format((float) $line->stockIn->amount_paid, 2)." is paid against {$line->stockIn->product_name}, more than its cost without this invoice, so it can't be cancelled."]);
                }
            }

            foreach ($amended as $line) {
                self::restoreRow($line, $reason, $userId);
            }

            VendorInvoiceLine::where('vendor_invoice_id', $invoice->id)->update(['active_marker' => null]);

            $snapshot = [
                'status' => $invoice->status->value,
                'vendor_id' => $invoice->vendor_id,
                'invoice_number' => $invoice->invoice_number,
                'lines' => $invoice->lines->map(fn (VendorInvoiceLine $line) => [
                    'grn_line_id' => $line->grn_line_id,
                    'billed_quantity' => $line->billed_quantity,
                    'billed_amount' => (float) $line->billed_amount,
                    'amended' => $line->amended,
                ])->all(),
            ];

            $invoice->fill([
                'status' => VendorInvoiceStatus::Cancelled,
                'active_marker' => null,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => filled($reason) ? $reason : null,
            ])->save();

            ModuleRevision::create([
                'shop_id' => $invoice->shop_id,
                'revisionable_type' => VendorInvoice::class,
                'revisionable_id' => $invoice->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => filled($reason) ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $invoice;
        });
    }

    /**
     * Capped at what's owed and shared by what each batch still owes, through
     * the same path as Stock In History. The invoice itself stores nothing.
     */
    public static function pay(VendorInvoice $invoice, float $amount, ?int $userId): VendorInvoice
    {
        return DB::transaction(function () use ($invoice, $amount, $userId) {
            $invoice = self::lock($invoice);
            self::assertBranch($invoice);

            if (! $invoice->isApproved()) {
                throw ValidationException::withMessages(['payAmount' => 'Only an approved invoice can be paid.']);
            }

            $owed = $invoice->amountOwed();

            if (round($amount, 2) <= 0) {
                throw ValidationException::withMessages(['payAmount' => 'Enter an amount above zero.']);
            }

            if (round($amount, 2) > $owed) {
                throw ValidationException::withMessages(['payAmount' => 'Only Rs '.number_format($owed, 2).' is owed on this invoice.']);
            }

            $remaining = VendorInvoiceMatch::paise($amount);
            $remainingOwed = VendorInvoiceMatch::paise($owed);

            foreach ($invoice->lines as $line) {
                $rowOwed = VendorInvoiceMatch::paise($line->stockIn->amountOwed());

                if ($rowOwed === 0 || $remaining === 0) {
                    continue;
                }

                $share = min($rowOwed, (int) round($remaining * $rowOwed / $remainingOwed));
                $remaining -= $share;
                $remainingOwed -= $rowOwed;

                if ($share > 0) {
                    RecordStockInPayment::add($line->stockIn, $share / 100, $userId, StockInPaymentSource::InvoicePayment, $invoice->id);
                }
            }

            return $invoice->fresh(['lines.stockIn']);
        });
    }

    /** Whether a batch's cost is held by an approved invoice, even one approved with no change. */
    public static function holdsCost(StockIn $row): bool
    {
        return VendorInvoiceLine::where('stock_in_id', $row->id)
            ->whereNotNull('active_marker')
            ->whereHas('invoice', fn ($query) => $query->where('status', VendorInvoiceStatus::Approved->value))
            ->exists();
    }

    /** @return array<string, mixed> */
    public static function evaluate(VendorInvoice $invoice): array
    {
        $invoice->load(['lines.stockIn', 'lines.grnLine.poLine', 'charges']);

        return VendorInvoiceMatch::evaluate($invoice->lines, $invoice->charges);
    }

    /** Invoice, then its rows in id order, always, so two people can't deadlock. */
    protected static function lock(VendorInvoice $invoice): VendorInvoice
    {
        $invoice = VendorInvoice::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        $invoice->load(['lines.grnLine.poLine', 'charges']);

        $rows = self::lockRows($invoice->lines);

        foreach ($invoice->lines as $line) {
            $line->setRelation('stockIn', $rows->get($line->stock_in_id));
        }

        return $invoice;
    }

    /** @return Collection<int, StockIn> */
    protected static function lockRows(Collection $lines): Collection
    {
        return StockIn::withoutGlobalScope(ExcludesCancelledScope::class)
            ->whereIn('id', $lines->pluck('stock_in_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    protected static function assertBranch(VendorInvoice $invoice): void
    {
        if (! $invoice->isWithinUsersBranch()) {
            throw ValidationException::withMessages(['invoice' => 'This invoice covers goods received at another branch, so it needs someone with access to the whole shop.']);
        }
    }

    protected static function correctRow(VendorInvoiceLine $line, float $newTotal, string $reason, ?int $userId): void
    {
        $row = $line->stockIn;

        ModuleRevision::create([
            'shop_id' => $row->shop_id,
            'revisionable_type' => StockIn::class,
            'revisionable_id' => $row->id,
            'action' => RevisionAction::Edited,
            'user_id' => $userId,
            'reason' => $reason,
            'snapshot' => [
                'cost_price' => $row->cost_price !== null ? (float) $row->cost_price : null,
                'total_cost' => $row->total_cost !== null ? (float) $row->total_cost : null,
                'payment_status' => $row->payment_status->value,
                'vendor_invoice_id' => $line->vendor_invoice_id,
            ],
            'created_at' => now(),
        ]);

        $line->update([
            'amended' => true,
            'previous_cost_price' => $row->cost_price,
            'previous_total_cost' => $row->total_cost,
            'unsold_quantity_at_approval' => $row->quantity_remaining,
        ]);

        $row->update([
            'cost_price' => round($newTotal / (float) $row->quantity, 2),
            'total_cost' => $newTotal,
            'payment_status' => self::statusFor($newTotal, (float) $row->amount_paid),
        ]);
    }

    protected static function restoreRow(VendorInvoiceLine $line, ?string $reason, ?int $userId): void
    {
        $row = $line->stockIn;

        ModuleRevision::create([
            'shop_id' => $row->shop_id,
            'revisionable_type' => StockIn::class,
            'revisionable_id' => $row->id,
            'action' => RevisionAction::Edited,
            'user_id' => $userId,
            'reason' => filled($reason) ? $reason : 'Vendor invoice cancelled',
            'snapshot' => [
                'cost_price' => $row->cost_price !== null ? (float) $row->cost_price : null,
                'total_cost' => $row->total_cost !== null ? (float) $row->total_cost : null,
                'payment_status' => $row->payment_status->value,
                'vendor_invoice_id' => $line->vendor_invoice_id,
            ],
            'created_at' => now(),
        ]);

        $row->update([
            'cost_price' => $line->previous_cost_price,
            'total_cost' => $line->previous_total_cost,
            'payment_status' => self::statusFor((float) ($line->previous_total_cost ?? 0), (float) $row->amount_paid),
        ]);
    }

    /** amountOwed() returns 0 for anything Paid, so this must follow a change in cost. */
    protected static function statusFor(float $total, float $paid): PaymentStatus
    {
        return match (true) {
            $total > 0 && $paid >= $total => PaymentStatus::Paid,
            $paid > 0 => PaymentStatus::Partial,
            default => PaymentStatus::Unpaid,
        };
    }
}
