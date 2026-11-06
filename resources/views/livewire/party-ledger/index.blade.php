<?php

use App\Enums\PartyLedgerDirection;
use App\Enums\PartyLedgerEntryKind;
use App\Enums\PaymentStatus;
use App\Enums\RecordStatus;
use App\Exports\TableExport;
use App\Livewire\Concerns\GuardsExportSize;
use App\Livewire\Concerns\Toasts;
use App\Models\BalanceLoad;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\NadraVerification;
use App\Models\PartyLedgerEntry;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\ServicePayment;
use App\Models\SimSale;
use App\Models\StockIn;
use App\Models\StockInPayment;
use App\Models\UdhaarTransaction;
use App\Models\Vendor;
use App\Models\WalletLoad;
use App\Services\TableExportService;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Party Ledger')] class extends Component
{
    use GuardsExportSize, Toasts;

    #[Url(as: 'party')]
    public string $partyType = 'customer';

    #[Url(as: 'customer')]
    public ?int $customerId = null;

    #[Url(as: 'vendor')]
    public ?int $vendorId = null;

    public string $from = '';
    public string $to = '';

    public string $entryKind = 'adjustment';
    public string $entryDirection = 'debit';
    public string $entryAmount = '';
    public string $entryDate = '';
    public string $entryNote = '';

    public ?int $cancelingEntryId = null;
    public string $cancelReason = '';

    public string $rangePreset = '3months';
    public bool $withBalanceOnly = false;

    /**
     * Customers and vendors belong to the whole shop, so this screen shows
     * every branch's lines and balances to everyone, restricted staff
     * included. boot() runs on every Livewire request, not just the first.
     */
    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(): void
    {
        if (! $this->isVendorSide()) {
            $this->partyType = 'customer';
        }

        $this->from = now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
        $this->entryDate = now()->toDateString();
    }

    /**
     * Vendor data belongs to the Vendors permission, the same way Stock-In
     * create only offers vendors to users who hold it.
     */
    protected function vendorsAvailable(): bool
    {
        return Auth::user()->hasAccessTo('vendors');
    }

    protected function isVendorSide(): bool
    {
        return $this->partyType === 'vendor' && $this->vendorsAvailable();
    }

    /**
     * Party lookups go through the tenant ShopScope, so an id from another
     * shop simply resolves to nothing.
     */
    protected function party(): Customer|Vendor|null
    {
        if ($this->isVendorSide()) {
            return $this->vendorId ? Vendor::find($this->vendorId) : null;
        }

        return $this->customerId ? Customer::find($this->customerId) : null;
    }

    protected function partyColumn(): string
    {
        return $this->isVendorSide() ? 'vendor_id' : 'customer_id';
    }

    protected function partyNoun(): string
    {
        return $this->isVendorSide() ? 'vendor' : 'customer';
    }

    public function setPartyType(string $type): void
    {
        if (! in_array($type, ['customer', 'vendor'], true) || ($type === 'vendor' && ! $this->vendorsAvailable())) {
            return;
        }

        $this->partyType = $type;
        $this->resetErrorBag();
        $this->refreshAllTime();
    }

    public function applyPreset(string $preset): void
    {
        $from = match ($preset) {
            'month' => now()->startOfMonth(),
            '3months' => now()->subMonthsNoOverflow(2)->startOfMonth(),
            'year' => now()->startOfYear(),
            'all' => $this->earliestDate() ?? now(),
            default => null,
        };

        if ($from === null) {
            return;
        }

        $this->rangePreset = $preset;
        $this->from = $from->toDateString();
        $this->to = now()->toDateString();
    }

    public function updatedFrom(): void
    {
        $this->rangePreset = '';
    }

    public function updatedTo(): void
    {
        $this->rangePreset = '';
    }

    public function updatedCustomerId(): void
    {
        $this->refreshAllTime();
    }

    public function updatedVendorId(): void
    {
        $this->refreshAllTime();
    }

    /**
     * "All time" starts at the chosen party's first record, so it has to
     * move when the party changes.
     */
    protected function refreshAllTime(): void
    {
        if ($this->rangePreset === 'all') {
            $this->applyPreset('all');
        }
    }

    protected function earliestDate(): ?Carbon
    {
        $party = $this->party();

        if (! $party) {
            return null;
        }

        $dates = $party instanceof Vendor
            ? [
                StockIn::where('vendor_id', $party->id)->min('stock_date'),
                StockInPayment::whereIn('stock_in_id', StockIn::where('vendor_id', $party->id)->select('id'))->min('payment_date'),
            ]
            : [
                Sale::where('customer_id', $party->id)->min('created_at'),
                SalePayment::whereIn('sale_id', Sale::where('customer_id', $party->id)->select('id'))->min('payment_date'),
                UdhaarTransaction::where('customer_id', $party->id)->min('transaction_date'),
            ];
        $dates[] = PartyLedgerEntry::where($this->partyColumn(), $party->id)->min('entry_date');

        $earliest = collect($dates)->filter()->map(fn ($date) => Carbon::parse($date))->min();

        return $earliest?->startOfDay();
    }

    /**
     * Ids of parties with a non-zero balance on their statement (all time):
     * Sales or Udhaar for a customer, what we owe for a vendor, or their own
     * Opening & adjustments. Read from the same figures the statement uses.
     *
     * @return array<int, true>
     */
    protected function partyIdsWithBalance(): array
    {
        $column = $this->partyColumn();
        $sign = $this->isVendorSide() ? -1 : 1;

        $ids = PartyLedgerEntry::whereNotNull($column)->get([$column, 'direction', 'amount'])
            ->groupBy($column)
            ->filter(fn (Collection $entries) => round($entries->sum(fn (PartyLedgerEntry $e) => $sign * $e->direction->sign() * (float) $e->amount), 2) != 0.0)
            ->keys();

        if ($this->isVendorSide()) {
            $ids = $ids->concat(
                StockIn::whereNotNull('vendor_id')->where('payment_status', '!=', PaymentStatus::Paid->value)->get()
                    ->groupBy('vendor_id')
                    ->filter(fn (Collection $entries) => $entries->sum(fn (StockIn $entry) => $entry->amountOwed()) > 0)
                    ->keys()
            );
        } else {
            $ids = $ids
                ->concat(
                    Sale::whereNotNull('customer_id')->withSum('payments', 'amount')->get()
                        ->filter(fn (Sale $sale) => $sale->amountDue() > 0)
                        ->pluck('customer_id')
                )
                ->concat(UdhaarTransaction::balancesByCustomer()->filter(fn (float $balance) => round($balance, 2) != 0.0)->keys());
        }

        return $ids->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * Unsettled amounts from the other customer modules, listed for reference
     * only — the same records and amountOwed() figures the Udhaar screen
     * shows as "Other Amounts Owed". Sales are left out because they already
     * have their own section. Nothing here is part of any balance above.
     */
    protected function otherDues(Customer $customer): Collection
    {
        $rows = collect();
        $add = function (string $source, iterable $records, \Closure $describe) use ($rows) {
            foreach ($records as $record) {
                if ($record->amountOwed() > 0) {
                    $rows->push(['source' => $source, 'description' => $describe($record), 'date' => $record->created_at, 'amountDue' => (float) $record->amountOwed()]);
                }
            }
        };

        $add('Wallet Load', WalletLoad::where('customer_id', $customer->id)->where('direction', 'cash_in')->get(), fn (WalletLoad $r) => $r->receiptNumber().' · '.$r->provider);
        $add('Balance Load', BalanceLoad::where('customer_id', $customer->id)->get(), fn (BalanceLoad $r) => $r->receiptNumber().' · '.$r->network);
        $add('Bill', BillPayment::where('customer_id', $customer->id)->with('billCategory')->get(), fn (BillPayment $r) => $r->receiptNumber().' · '.($r->billCategory?->name ?? $r->consumer_name));
        $add('Repair', Repair::where('customer_id', $customer->id)->get(), fn (Repair $r) => $r->receiptNumber().' · '.$r->categoryLabel());
        $add('NADRA Verification', NadraVerification::where('customer_id', $customer->id)->get(), fn (NadraVerification $r) => $r->receiptNumber());
        $add('SIM Sale', SimSale::where('customer_id', $customer->id)->get(), fn (SimSale $r) => $r->receiptNumber().' · '.$r->network);
        $add('Service', ServicePayment::where('customer_id', $customer->id)->with('serviceCategory')->get(), fn (ServicePayment $r) => $r->receiptNumber().' · '.($r->serviceCategory?->name ?? '—'));

        return $rows->sortByDesc('date')->values();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function range(): array
    {
        try {
            $from = Carbon::parse($this->from)->startOfDay();
        } catch (\Throwable) {
            $from = now()->subMonthsNoOverflow(2)->startOfMonth();
        }

        try {
            $to = Carbon::parse($this->to)->endOfDay();
        } catch (\Throwable) {
            $to = now()->endOfDay();
        }

        return $from->lte($to) ? [$from, $to] : [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
    }

    protected function rangeLabel(): string
    {
        [$from, $to] = $this->range();

        return $from->format('d M Y').' – '.$to->format('d M Y');
    }

    /**
     * One running-balance section from a list of dated movements: everything
     * before the range is folded into "brought forward", movements after the
     * range are left out, and the rest are listed in order. $sign is +1 when
     * a debit raises the balance (a customer owes the shop) and -1 when a
     * credit does (the shop owes a vendor).
     *
     * @param  Collection<int, array{date: Carbon, sort: string, debit: float, credit: float}>  $movements
     */
    protected function section(Collection $movements, int $sign = 1): array
    {
        [$from, $to] = $this->range();

        $movements = $movements->sortBy('sort')->values();

        $opening = $movements
            ->filter(fn (array $m) => $m['date']->lt($from))
            ->sum(fn (array $m) => $sign * ($m['debit'] - $m['credit']));

        $running = $opening;
        $rows = $movements
            ->filter(fn (array $m) => $m['date']->between($from, $to))
            ->map(function (array $m) use (&$running, $sign) {
                $running += $sign * ($m['debit'] - $m['credit']);

                return $m + ['balance' => $running];
            })
            ->values();

        return [
            'opening' => (float) $opening,
            'rows' => $rows,
            'debits' => (float) $rows->sum('debit'),
            'credits' => (float) $rows->sum('credit'),
            'closing' => (float) $running,
        ];
    }

    protected function sortKey(Carbon $date, int ...$parts): string
    {
        return $date->format('Ymd').collect($parts)->map(fn (int $p) => '-'.str_pad((string) $p, 10, '0', STR_PAD_LEFT))->implode('');
    }

    protected function movement(string $key, Carbon $date, string $sort, string $description, ?string $detail, ?string $url, string $side, float $amount, ?PartyLedgerEntry $entry = null): array
    {
        return [
            'key' => $key,
            'date' => $date->copy()->startOfDay(),
            'sort' => $sort,
            'description' => $description,
            'detail' => $detail,
            'url' => $url,
            'side' => $side,
            'debit' => $side === 'debit' ? $amount : 0.0,
            'credit' => $side === 'credit' ? $amount : 0.0,
            'entry' => $entry,
        ];
    }

    /**
     * Sales (debit) and their payments (credit). Cancelled sales — and so
     * their payments — are excluded by the Sale global scope, the same way
     * Customer::salesOutstandingBalance() excludes them.
     */
    protected function salesMovements(Customer $customer): Collection
    {
        $canOpen = Auth::user()->hasAccessTo('sales');

        return Sale::where('customer_id', $customer->id)->with('payments')->get()
            ->flatMap(function (Sale $sale) use ($canOpen) {
                $url = $canOpen ? route('sales.show', $sale) : null;

                $movements = [$this->movement('sale-'.$sale->id, $sale->created_at, $this->sortKey($sale->created_at, $sale->id, 0, 0), 'Sale '.$sale->invoiceNumber(), null, $url, 'debit', (float) $sale->total)];

                foreach ($sale->payments as $payment) {
                    if ((float) $payment->amount <= 0) {
                        continue;
                    }

                    $movements[] = $this->movement('sale-payment-'.$payment->id, $payment->payment_date, $this->sortKey($payment->payment_date, $sale->id, 1, $payment->id), 'Payment received', $sale->invoiceNumber(), $url, 'credit', (float) $payment->amount);
                }

                return $movements;
            });
    }

    /**
     * Udhaar transactions, including reversal rows — the same set that makes
     * up Customer::udhaarBalance(). Cancelled ones are excluded by the
     * HasCancellation global scope.
     */
    protected function udhaarMovements(Customer $customer): Collection
    {
        $url = Auth::user()->hasAccessTo('udhaar') ? route('udhaar.show', $customer) : null;

        return $customer->udhaarTransactions->map(fn (UdhaarTransaction $transaction) => $this->movement(
            'udhaar-'.$transaction->id,
            $transaction->transaction_date,
            $this->sortKey($transaction->transaction_date, $transaction->id),
            $transaction->type->label(),
            $transaction->note,
            $url,
            $transaction->type->sign() > 0 ? 'debit' : 'credit',
            (float) $transaction->amount,
        ));
    }

    /**
     * Stock-Ins (credit — the shop owes more) and their dated payments
     * (debit). Read-only: cancelled Stock-Ins, and so their payment rows, are
     * excluded by the HasCancellation global scope, as in Vendor::totalOwed().
     *
     * Where a Stock-In's cost minus its payment rows differs from what
     * StockIn::amountOwed() says is owed (marked paid with a gap, an
     * overpayment, no cost price), one computed, labelled line makes up the
     * difference, so this balance always ends at Vendor::totalOwed().
     */
    protected function purchaseMovements(Vendor $vendor): Collection
    {
        $url = Auth::user()->hasAccessTo('stock-ins') ? route('stock-ins.history', ['vendorFilter' => $vendor->id]) : null;

        return StockIn::where('vendor_id', $vendor->id)->with(['payments', 'correctedByInvoice.invoice'])->get()
            ->flatMap(function (StockIn $entry) use ($url) {
                $cost = (float) ($entry->total_cost ?? 0);
                // Shown as received, then corrected on the day: past statements don't change.
                $correction = $entry->correctedByInvoice;
                $received = $correction !== null ? (float) ($correction->previous_total_cost ?? 0) : $cost;
                $movements = [$this->movement(
                    'stock-in-'.$entry->id,
                    $entry->stock_date,
                    $this->sortKey($entry->stock_date, $entry->id, 0, 0),
                    'Stock-In: '.$entry->product_name.' × '.$entry->unit->amount($entry->quantity),
                    $entry->total_cost === null ? 'No cost price recorded' : null,
                    $url,
                    'credit',
                    $received,
                )];

                if ($correction !== null && round($cost - $received, 2) != 0.0) {
                    $invoice = $correction->invoice;
                    $movements[] = $this->movement(
                        'vendor-invoice-correction-'.$correction->id,
                        $invoice->reviewed_at,
                        $this->sortKey($invoice->reviewed_at, $entry->id, 0, 1),
                        'Vendor invoice '.$invoice->number().': cost corrected',
                        $entry->product_name,
                        $url,
                        $cost > $received ? 'credit' : 'debit',
                        abs(round($cost - $received, 2)),
                    );
                }

                $lastDate = $entry->stock_date;
                foreach ($entry->payments as $payment) {
                    $movements[] = $this->movement(
                        'stock-in-payment-'.$payment->id,
                        $payment->payment_date,
                        $this->sortKey($payment->payment_date, $entry->id, 1, $payment->id),
                        $payment->source->label(),
                        null,
                        $url,
                        (float) $payment->amount >= 0 ? 'debit' : 'credit',
                        abs((float) $payment->amount),
                    );
                    $lastDate = $payment->payment_date->gt($lastDate) ? $payment->payment_date : $lastDate;
                }

                $raw = $cost - (float) $entry->payments->sum('amount');
                $difference = round($entry->amountOwed() - $raw, 2);

                if ($difference != 0.0) {
                    $label = match (true) {
                        $entry->total_cost === null => 'No cost price: payments not counted',
                        $entry->payment_status === PaymentStatus::Paid && $raw > 0 => 'Marked paid: remaining balance not owed',
                        $raw < 0 => 'Overpayment: not counted by Stock-In',
                        default => 'Adjusted to match Stock-In balance',
                    };

                    $movements[] = $this->movement(
                        'stock-in-adjust-'.$entry->id,
                        $lastDate,
                        $this->sortKey($lastDate, $entry->id, 2, 0),
                        $label,
                        'Computed so this balance matches Stock-In',
                        null,
                        $difference > 0 ? 'credit' : 'debit',
                        abs($difference),
                    );
                }

                return $movements;
            });
    }

    protected function manualMovements(Customer|Vendor $party): Collection
    {
        return PartyLedgerEntry::where($this->partyColumn(), $party->id)->with('user')->get()
            ->map(fn (PartyLedgerEntry $entry) => $this->movement(
                'entry-'.$entry->id,
                $entry->entry_date,
                $this->sortKey($entry->entry_date, $entry->id),
                $entry->kind->label(),
                $entry->note.($entry->user ? ' · by '.$entry->user->name : ''),
                null,
                $entry->direction->value,
                (float) $entry->amount,
                $entry,
            ));
    }

    /**
     * Every section is its own balance and they are never combined or netted
     * against each other. Sales and Udhaar always match the customer profile
     * and Udhaar screen; Purchases & payments always matches
     * Vendor::totalOwed().
     */
    protected function statement(Customer|Vendor $party): array
    {
        if ($party instanceof Vendor) {
            return [
                'purchases' => $this->section($this->purchaseMovements($party), -1),
                'manual' => $this->section($this->manualMovements($party), -1),
            ];
        }

        return [
            'sales' => $this->section($this->salesMovements($party)),
            'udhaar' => $this->section($this->udhaarMovements($party)),
            'manual' => $this->section($this->manualMovements($party)),
        ];
    }

    protected function sectionMeta(): array
    {
        if ($this->isVendorSide()) {
            return [
                'purchases' => ['title' => 'Purchases & payments', 'description' => 'Each Stock-In is a credit (we owe more); each payment to the vendor is a debit.'],
                'manual' => ['title' => 'Opening & adjustments', 'description' => 'Entered by hand on this screen. Kept separate from Stock-In.'],
            ];
        }

        return [
            'sales' => ['title' => 'Sales', 'description' => 'Sales on credit and the payments received against them.'],
            'udhaar' => ['title' => 'Udhaar', 'description' => 'Udhaar given and repaid. Recorded on the Udhaar screen.'],
            'manual' => ['title' => 'Opening & adjustments', 'description' => 'Entered by hand on this screen. Kept separate from Sales and Udhaar.'],
        ];
    }

    public function with(): array
    {
        $party = $this->party();
        [$rangeFrom, $rangeTo] = $this->range();
        $vendorSide = $this->isVendorSide();

        $options = ($vendorSide ? Vendor::query() : Customer::query())->orderBy('name')->get(['id', 'name', 'phone']);
        if ($this->withBalanceOnly) {
            $withBalance = $this->partyIdsWithBalance();
            // Keep the chosen party in the list so the picker still shows it.
            $options = $options->filter(fn ($option) => isset($withBalance[$option->id]) || $option->id === $party?->id)->values();
        }

        return [
            'vendorSide' => $vendorSide,
            'vendorsAvailable' => $this->vendorsAvailable(),
            'customers' => $vendorSide ? collect() : $options,
            'vendors' => $vendorSide ? $options : collect(),
            'party' => $party,
            'statement' => $party ? $this->statement($party) : null,
            'otherDues' => $party instanceof Customer ? $this->otherDues($party) : collect(),
            'udhaarUrl' => $party instanceof Customer && Auth::user()->hasAccessTo('udhaar') ? route('udhaar.show', $party) : null,
            'sectionMeta' => $this->sectionMeta(),
            'rangeLabel' => $this->rangeLabel(),
            'rangeFrom' => $rangeFrom,
            'rangeTo' => $rangeTo,
        ];
    }

    protected function hasOpeningEntry(Customer|Vendor $party): bool
    {
        return PartyLedgerEntry::where($this->partyColumn(), $party->id)->where('kind', PartyLedgerEntryKind::Opening)->exists();
    }

    public function openAddEntry(): void
    {
        $party = $this->party();
        abort_unless($party, 404);

        $this->reset(['entryAmount', 'entryNote']);
        $this->entryKind = $this->hasOpeningEntry($party) ? PartyLedgerEntryKind::Adjustment->value : PartyLedgerEntryKind::Opening->value;
        $this->entryDirection = $this->isVendorSide() ? PartyLedgerDirection::Credit->value : PartyLedgerDirection::Debit->value;
        $this->entryDate = now()->toDateString();
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'ledger-entry-form');
    }

    public function saveEntry(): void
    {
        abort_unless(Auth::user()->hasAccessTo('party-ledger'), 403);
        $party = $this->party();
        abort_unless($party, 404);

        $this->validate([
            'entryKind' => ['required', Rule::enum(PartyLedgerEntryKind::class)],
            'entryDirection' => ['required', Rule::enum(PartyLedgerDirection::class)],
            'entryAmount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'entryDate' => ['required', 'date', 'before_or_equal:today'],
            'entryNote' => ['required', 'string', 'max:500'],
        ], [], [
            'entryKind' => 'type',
            'entryDirection' => 'direction',
            'entryAmount' => 'amount',
            'entryDate' => 'date',
            'entryNote' => 'note',
        ]);

        $saved = DB::transaction(function () use ($party) {
            $party::whereKey($party->id)->lockForUpdate()->first();

            if ($this->entryKind === PartyLedgerEntryKind::Opening->value && $this->hasOpeningEntry($party)) {
                return false;
            }

            PartyLedgerEntry::create([
                $this->partyColumn() => $party->id,
                'user_id' => Auth::id(),
                'kind' => $this->entryKind,
                'direction' => $this->entryDirection,
                'amount' => $this->entryAmount,
                'entry_date' => $this->entryDate,
                'note' => $this->entryNote,
            ]);

            return true;
        });

        if (! $saved) {
            $this->addError('entryKind', 'This '.$this->partyNoun().' already has an opening balance. Cancel it first, or add an adjustment instead.');

            return;
        }

        $this->dispatch('close-modal', name: 'ledger-entry-form');
        $this->reset(['entryAmount', 'entryNote']);
        $this->toastSuccess('Entry added.');
    }

    public function closeEntryForm(): void
    {
        $this->dispatch('close-modal', name: 'ledger-entry-form');
        $this->resetErrorBag();
    }

    protected function partyEntries()
    {
        $party = $this->party();
        abort_unless($party, 404);

        return PartyLedgerEntry::where($this->partyColumn(), $party->id);
    }

    public function openCancelEntry(int $entryId): void
    {
        $entry = $this->partyEntries()->findOrFail($entryId);

        $this->cancelingEntryId = $entry->id;
        $this->cancelReason = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'cancel-entry-form');
    }

    public function cancelEntry(): void
    {
        abort_unless(Auth::user()->hasAccessTo('party-ledger'), 403);

        $this->validate(['cancelReason' => ['required', 'string', 'max:500']], [], ['cancelReason' => 'reason']);

        DB::transaction(function () {
            $entry = $this->partyEntries()->lockForUpdate()->findOrFail($this->cancelingEntryId);

            $entry->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancel_reason' => $this->cancelReason,
            ]);
        });

        $this->dispatch('close-modal', name: 'cancel-entry-form');
        $this->reset(['cancelingEntryId', 'cancelReason']);
        $this->toastSuccess('Entry cancelled.');
    }

    public function closeCancelEntry(): void
    {
        $this->dispatch('close-modal', name: 'cancel-entry-form');
        $this->reset(['cancelingEntryId', 'cancelReason']);
    }

    protected function exportSections(bool $forExcel): array
    {
        $statement = $this->statement($this->party());
        [$from, $to] = $this->range();

        $money = fn (?float $amount) => $forExcel
            ? $amount
            : ($amount === null ? '' : 'Rs '.number_format(abs($amount), 2).($amount < 0 ? ' (advance)' : ''));
        $date = fn (Carbon $d) => $forExcel ? TableExport::excelDate($d) : $d->format('d M Y');

        $sections = collect($this->sectionMeta())->map(function (array $meta, string $key) use ($statement, $money, $date, $from, $to) {
            $section = $statement[$key];

            $rows = collect([[$date($from), 'Balance brought forward', $money(null), $money(null), $money($section['opening'])]])
                ->concat($section['rows']->map(fn (array $row) => [
                    $date($row['date']),
                    TableExportService::sanitizeCell($row['description'].($row['detail'] ? ' — '.$row['detail'] : '')),
                    $money($row['side'] === 'debit' ? $row['debit'] : null),
                    $money($row['side'] === 'credit' ? $row['credit'] : null),
                    $money($row['balance']),
                ]))
                ->push([$date($to), 'Closing balance', $money($section['debits']), $money($section['credits']), $money($section['closing'])]);

            return [
                'title' => $meta['title'],
                'headers' => ['Date', 'Description', 'Debit', 'Credit', 'Balance'],
                'rows' => $rows->all(),
                'columnTypes' => ['date', 'string', 'currency', 'currency', 'currency'],
            ];
        })->values();

        $party = $this->party();
        $dues = $party instanceof Customer ? $this->otherDues($party) : collect();

        if ($dues->isNotEmpty()) {
            $sections->push([
                'title' => 'Other amounts owed (not part of the balances above)',
                'headers' => ['Date', 'Source', 'Description', 'Amount due'],
                'rows' => $dues->map(fn (array $due) => [
                    $date($due['date']),
                    $due['source'],
                    TableExportService::sanitizeCell($due['description']),
                    $money($due['amountDue']),
                ])->all(),
                'columnTypes' => ['date', 'string', 'string', 'currency'],
            ]);
        }

        return $sections->all();
    }

    protected function exportTitle(): string
    {
        return $this->isVendorSide()
            ? 'Party Ledger — Vendor: '.$this->party()->name
            : 'Party Ledger — '.$this->party()->name;
    }

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        if (! $this->party()) {
            $this->toastError('Choose a '.$this->partyNoun().' first.');

            return null;
        }

        return $this->guardExportSize(fn () => $exportService->toPdfSections(
            $this->exportTitle(),
            Auth::user()->shop->name,
            'Statement: '.$this->rangeLabel(),
            array_map(fn (array $s) => ['title' => $s['title'], 'headers' => $s['headers'], 'rows' => $s['rows']], $this->exportSections(forExcel: false)),
        ));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        if (! $this->party()) {
            $this->toastError('Choose a '.$this->partyNoun().' first.');

            return null;
        }

        return $this->guardExportSize(fn () => $exportService->toExcelSections(
            $this->exportTitle(),
            Auth::user()->shop->name,
            'Statement: '.$this->rangeLabel(),
            $this->exportSections(forExcel: true),
        ));
    }
}; ?>

@php
    $balanceText = fn (float $amount) => 'Rs '.number_format(abs($amount), 2);
@endphp

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Party Ledger</h1>
    </x-slot>

    <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-3">
        @if ($vendorsAvailable)
            <div class="inline-flex rounded-lg border border-slate-300 p-1" role="group" aria-label="Party type">
                @foreach (['customer' => 'Customers', 'vendor' => 'Vendors'] as $type => $label)
                    <button
                        type="button"
                        wire:click="setPartyType('{{ $type }}')"
                        aria-pressed="{{ $partyType === $type ? 'true' : 'false' }}"
                        @class([
                            'rounded-md px-3 py-1.5 text-sm font-medium transition',
                            'bg-brand-600 text-white' => $partyType === $type,
                            'text-slate-600 hover:text-slate-900' => $partyType !== $type,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        @endif

        <x-ui.checkbox wire:model.live="withBalanceOnly" label="Only {{ $vendorSide ? 'vendors' : 'customers' }} with a balance" />
    </div>

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="flex w-full flex-wrap items-end gap-4 sm:w-auto">
            {{-- Keyed by the filter: when the option list changes the native select loses its
                 selection, so rebuild the picker and let it read the chosen party again. --}}
            <div class="w-full sm:w-72" wire:key="picker-{{ $partyType }}-{{ $withBalanceOnly ? 'with-balance' : 'all' }}">
                @if ($vendorSide)
                    <x-ui.field label="Vendor" name="vendorId" for="vendorId">
                        <x-ui.select wire:model.live="vendorId" id="vendorId">
                            <option value="">Choose a vendor…</option>
                            @foreach ($vendors as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}{{ $option->phone ? ' · '.$option->phone : '' }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @else
                    <x-ui.field label="Customer" name="customerId" for="customerId">
                        <x-ui.select wire:model.live="customerId" id="customerId">
                            <option value="">Choose a customer…</option>
                            @foreach ($customers as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}{{ $option->phone ? ' · '.$option->phone : '' }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif
            </div>

            <x-ui.field label="From" name="from" for="from">
                <x-ui.input wire:model.live="from" id="from" type="date" />
            </x-ui.field>

            <x-ui.field label="To" name="to" for="to">
                <x-ui.input wire:model.live="to" id="to" type="date" />
            </x-ui.field>

            <div class="flex w-full flex-wrap gap-1.5 sm:w-auto sm:pb-1" role="group" aria-label="Date range">
                @foreach (['month' => 'This month', '3months' => 'Last 3 months', 'year' => 'This year', 'all' => 'All time'] as $preset => $label)
                    <button
                        type="button"
                        wire:click="applyPreset('{{ $preset }}')"
                        aria-pressed="{{ $rangePreset === $preset ? 'true' : 'false' }}"
                        @class([
                            'rounded-full border px-3 py-1 text-xs font-medium transition',
                            'border-brand-600 bg-brand-50 text-brand-700' => $rangePreset === $preset,
                            'border-slate-300 text-slate-600 hover:border-slate-400 hover:text-slate-900' => $rangePreset !== $preset,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.export-dropdown />
            @if ($party)
                <x-ui.button wire:click="openAddEntry">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Add Entry
                </x-ui.button>
            @endif
        </div>
    </div>

    @if (! $party)
        @if ($vendorSide)
            <x-ui.empty-state
                title="Choose a vendor"
                description="Pick a vendor above to see their statement: purchases and payments, and opening balances and adjustments, each with its own running balance."
            />
        @else
            <x-ui.empty-state
                title="Choose a customer"
                description="Pick a customer above to see their statement: Sales, Udhaar, and opening balances and adjustments, each with its own running balance."
            />
        @endif
    @else
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 class="text-base font-semibold text-slate-900">
                <a href="{{ $vendorSide ? route('vendors.show', $party) : route('customers.show', $party) }}" wire:navigate class="hover:text-brand-700">{{ $party->name }}</a>
                @if ($party->phone)
                    <span class="ml-1 text-sm font-normal text-slate-500">{{ $party->phone }}</span>
                @endif
            </h2>
            <p class="text-sm text-slate-500">Statement {{ $rangeLabel }}</p>
        </div>

        @if ($vendorSide)
            <div class="mb-2 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.stat
                    label="Owed to vendor"
                    :value="$balanceText($statement['purchases']['closing'])"
                    :sub="$statement['purchases']['closing'] < 0 ? 'Paid in advance' : 'Purchases & payments closing balance'"
                />
                <x-ui.stat
                    label="Opening & adjustments"
                    :value="$balanceText($statement['manual']['closing'])"
                    :sub="$statement['manual']['closing'] < 0 ? 'In our favour' : 'Closing balance'"
                />
            </div>
            <p class="mb-6 text-xs text-slate-500">Two separate balances. They are never added together.</p>
        @else
            <div class="mb-2 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-ui.stat label="Sales outstanding" :value="$balanceText($statement['sales']['closing'])" sub="Closing balance" />
                <x-ui.stat
                    label="Udhaar balance"
                    :value="$balanceText($statement['udhaar']['closing'])"
                    :sub="match (true) { $statement['udhaar']['closing'] > 0 => 'Due', $statement['udhaar']['closing'] < 0 => 'Advance', default => 'Settled' }"
                />
                <x-ui.stat
                    label="Opening & adjustments"
                    :value="$balanceText($statement['manual']['closing'])"
                    :sub="$statement['manual']['closing'] < 0 ? 'In customer\'s favour' : 'Closing balance'"
                />
            </div>
            <p class="mb-6 text-xs text-slate-500">Three separate balances. They are never added together.</p>
        @endif

        <div class="space-y-6">
            @foreach ($sectionMeta as $key => $meta)
                @php $section = $statement[$key]; @endphp
                <section wire:key="section-{{ $key }}">
                    <div class="mb-2">
                        <h3 class="text-sm font-semibold text-slate-900">{{ $meta['title'] }}</h3>
                        <p class="text-xs text-slate-500">{{ $meta['description'] }}</p>
                    </div>
                    {{-- Phones: a stacked list, so the amount and running balance are visible without scrolling sideways. --}}
                    <div class="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white text-sm shadow-card sm:hidden">
                        <div class="flex items-baseline justify-between gap-3 bg-slate-50/60 px-4 py-2.5">
                            <div>
                                <p class="font-medium text-slate-600">Balance brought forward</p>
                                <p class="text-xs text-slate-500">{{ $rangeFrom->format('d M Y') }}</p>
                            </div>
                            <p class="whitespace-nowrap font-medium tabular-nums text-slate-900">{{ $balanceText($section['opening']) }}{{ $section['opening'] < 0 ? ' (advance)' : '' }}</p>
                        </div>

                        @forelse ($section['rows'] as $row)
                            <div wire:key="m-{{ $row['key'] }}" class="flex items-start justify-between gap-3 px-4 py-2.5">
                                <div class="min-w-0">
                                    @if ($row['url'])
                                        <a href="{{ $row['url'] }}" wire:navigate class="font-medium text-slate-800 hover:text-brand-700">{{ $row['description'] }}</a>
                                    @else
                                        <p class="font-medium text-slate-800">{{ $row['description'] }}</p>
                                    @endif
                                    <p class="text-xs text-slate-500">{{ $row['date']->format('d M Y') }}@if ($row['detail']) · {{ $row['detail'] }}@endif</p>
                                    @if ($key === 'manual')
                                        <button type="button" wire:click="openCancelEntry({{ $row['entry']->id }})" class="mt-1 text-xs font-medium text-red-600 hover:text-red-700">Cancel</button>
                                    @endif
                                </div>
                                <div class="shrink-0 text-right tabular-nums">
                                    @php $raises = ($row['side'] === 'debit') !== $vendorSide; @endphp
                                    <p @class(['whitespace-nowrap font-medium', 'text-red-600' => $raises, 'text-emerald-600' => ! $raises])>
                                        {{ $raises ? '+' : '−' }} Rs {{ number_format($row['side'] === 'debit' ? $row['debit'] : $row['credit'], 2) }}
                                    </p>
                                    <p class="whitespace-nowrap text-xs text-slate-500">Bal {{ $balanceText($row['balance']) }}{{ $row['balance'] < 0 ? ' (adv.)' : '' }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="px-4 py-3 text-center text-slate-400">No entries in this period</p>
                        @endforelse

                        <div class="flex items-baseline justify-between gap-3 bg-slate-50 px-4 py-2.5">
                            <div>
                                <p class="font-semibold text-slate-900">Closing balance</p>
                                <p class="text-xs text-slate-500">{{ $rangeTo->format('d M Y') }}</p>
                            </div>
                            <p class="whitespace-nowrap font-semibold tabular-nums text-slate-900">{{ $balanceText($section['closing']) }}{{ $section['closing'] < 0 ? ' (advance)' : '' }}</p>
                        </div>
                    </div>

                    <x-ui.table class="hidden sm:block" :headers="$key === 'manual' ? ['Date', 'Description', 'Debit', 'Credit', 'Balance', ''] : ['Date', 'Description', 'Debit', 'Credit', 'Balance']">
                        <x-ui.table-row class="bg-slate-50/60">
                            <x-ui.table-cell class="text-slate-500">{{ $rangeFrom->format('d M Y') }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-medium text-slate-600">Balance brought forward</x-ui.table-cell>
                            <x-ui.table-cell></x-ui.table-cell>
                            <x-ui.table-cell></x-ui.table-cell>
                            <x-ui.table-cell class="font-medium text-slate-900">
                                {{ $balanceText($section['opening']) }}{{ $section['opening'] < 0 ? ' (advance)' : '' }}
                            </x-ui.table-cell>
                            @if ($key === 'manual')
                                <x-ui.table-cell></x-ui.table-cell>
                            @endif
                        </x-ui.table-row>

                        @forelse ($section['rows'] as $row)
                            <x-ui.table-row wire:key="{{ $row['key'] }}">
                                <x-ui.table-cell>{{ $row['date']->format('d M Y') }}</x-ui.table-cell>
                                <x-ui.table-cell class="whitespace-normal">
                                    <div class="flex min-w-[12rem] flex-col">
                                        @if ($row['url'])
                                            <a href="{{ $row['url'] }}" wire:navigate class="font-medium text-slate-800 hover:text-brand-700">{{ $row['description'] }}</a>
                                        @else
                                            <span class="font-medium text-slate-800">{{ $row['description'] }}</span>
                                        @endif
                                        @if ($row['detail'])
                                            <span class="text-xs text-slate-500">{{ $row['detail'] }}</span>
                                        @endif
                                    </div>
                                </x-ui.table-cell>
                                <x-ui.table-cell>{{ $row['side'] === 'debit' ? 'Rs '.number_format($row['debit'], 2) : '' }}</x-ui.table-cell>
                                <x-ui.table-cell>{{ $row['side'] === 'credit' ? 'Rs '.number_format($row['credit'], 2) : '' }}</x-ui.table-cell>
                                <x-ui.table-cell class="font-medium text-slate-900">
                                    {{ $balanceText($row['balance']) }}{{ $row['balance'] < 0 ? ' (advance)' : '' }}
                                </x-ui.table-cell>
                                @if ($key === 'manual')
                                    <x-ui.table-cell align="right">
                                        <x-ui.button size="sm" variant="ghost" wire:click="openCancelEntry({{ $row['entry']->id }})" class="text-red-600 hover:bg-red-50">
                                            Cancel
                                        </x-ui.button>
                                    </x-ui.table-cell>
                                @endif
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-cell :colspan="$key === 'manual' ? 6 : 5" class="py-4 text-center text-slate-400">No entries in this period</x-ui.table-cell>
                            </x-ui.table-row>
                        @endforelse

                        <x-ui.table-row class="bg-slate-50">
                            <x-ui.table-cell class="text-slate-500">{{ $rangeTo->format('d M Y') }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-semibold text-slate-900">Closing balance</x-ui.table-cell>
                            <x-ui.table-cell class="text-slate-600">{{ $section['debits'] ? 'Rs '.number_format($section['debits'], 2) : '' }}</x-ui.table-cell>
                            <x-ui.table-cell class="text-slate-600">{{ $section['credits'] ? 'Rs '.number_format($section['credits'], 2) : '' }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-semibold text-slate-900">
                                {{ $balanceText($section['closing']) }}{{ $section['closing'] < 0 ? ' (advance)' : '' }}
                            </x-ui.table-cell>
                            @if ($key === 'manual')
                                <x-ui.table-cell></x-ui.table-cell>
                            @endif
                        </x-ui.table-row>
                    </x-ui.table>
                </section>
            @endforeach
        </div>

        @if ($otherDues->isNotEmpty())
            <section class="mt-8">
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Other amounts owed</h3>
                        <p class="text-xs text-slate-500">Unsettled Wallet Loads, Balance Loads, Bills, Repairs, NADRA, SIM Sales and Services. For reference only: not part of any balance above.</p>
                    </div>
                    @if ($udhaarUrl)
                        <a href="{{ $udhaarUrl }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">Record payments on the Udhaar screen →</a>
                    @endif
                </div>
                <x-ui.table :headers="['Date', 'Source', 'Description', 'Amount due']">
                    @foreach ($otherDues as $due)
                        <x-ui.table-row wire:key="due-{{ $loop->index }}">
                            <x-ui.table-cell>{{ $due['date']->format('d M Y') }}</x-ui.table-cell>
                            <x-ui.table-cell><x-ui.badge variant="neutral">{{ $due['source'] }}</x-ui.badge></x-ui.table-cell>
                            <x-ui.table-cell class="whitespace-normal">{{ $due['description'] }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-medium text-slate-900">Rs {{ number_format($due['amountDue'], 2) }}</x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                    <x-ui.table-row class="bg-slate-50">
                        <x-ui.table-cell></x-ui.table-cell>
                        <x-ui.table-cell></x-ui.table-cell>
                        <x-ui.table-cell class="font-semibold text-slate-900">Total</x-ui.table-cell>
                        <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($otherDues->sum('amountDue'), 2) }}</x-ui.table-cell>
                    </x-ui.table-row>
                </x-ui.table>
            </section>
        @endif
    @endif

    <x-ui.modal name="ledger-entry-form" max-width="sm">
        <form wire:submit="saveEntry" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Add Entry</h2>
            <p class="mt-1 text-sm text-slate-500">
                @if ($vendorSide)
                    Counts only toward this vendor's "Opening & adjustments" balance, never Stock-In. To record a payment against a Stock-In, use Stock-In history.
                @else
                    Counts only toward this customer's "Opening & adjustments" balance, never Sales or Udhaar. To record udhaar, use the Udhaar screen.
                @endif
            </p>

            <div class="mt-5 space-y-5">
                {{-- openAddEntry() sets these defaults on the server, and the select only reads its value
                     when it starts, so key each one by its value (and party) to rebuild it on change. --}}
                <div wire:key="entry-kind-{{ $entryKind }}">
                    <x-ui.field label="Type" name="entryKind" for="entryKind">
                        <x-ui.select wire:model="entryKind" id="entryKind">
                            @foreach (\App\Enums\PartyLedgerEntryKind::cases() as $kind)
                                <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>

                <div wire:key="entry-direction-{{ $partyType }}-{{ $entryDirection }}">
                    <x-ui.field label="Direction" name="entryDirection" for="entryDirection">
                        <x-ui.select wire:model="entryDirection" id="entryDirection">
                            @foreach (\App\Enums\PartyLedgerDirection::cases() as $direction)
                                <option value="{{ $direction->value }}">{{ $vendorSide ? $direction->vendorLabel() : $direction->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>

                <x-ui.field label="Amount" name="entryAmount" for="entryAmount">
                    <x-ui.input wire:model="entryAmount" id="entryAmount" type="number" min="0.01" step="0.01" class="text-lg" />
                </x-ui.field>

                <x-ui.field label="Date" name="entryDate" for="entryDate">
                    <x-ui.input wire:model="entryDate" id="entryDate" type="date" />
                </x-ui.field>

                <x-ui.field label="Note" name="entryNote" for="entryNote" help="Required. Why this entry is being made.">
                    <x-ui.input wire:model="entryNote" id="entryNote" maxlength="500" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeEntryForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveEntry">
                    Save Entry
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="cancel-entry-form" max-width="sm">
        <form wire:submit="cancelEntry" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Cancel This Entry?</h2>
            <p class="mt-2 text-sm text-slate-600">
                The entry will be marked <strong>Cancelled</strong> and stop counting toward the balance. It is kept on record. This cannot be undone.
            </p>

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="cancelReason">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" maxlength="500" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelEntry">
                    Keep Entry
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelEntry" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">
                    Cancel Entry
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
