<?php

use App\Actions\CancelWalletLoad;
use App\Actions\UpdateWalletLoad;
use App\Enums\PaymentStatus;
use App\Enums\WalletLoadDirection;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\ShopAccount;
use App\Models\WalletLoad;
use App\ReportQueries\WalletLoadReport;
use App\Services\WalletLoadReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Wallet Load History')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource;

    use Toasts, WithPagination;

    public string $tab = 'cash_in';
    public string $from = '';
    public string $to = '';
    public string $provider = '';
    public string $shopAccountId = '';
    public string $paymentStatusFilter = '';

    public ?int $payingId = null;
    public string $paymentAmount = '';

    public ?int $editingId = null;
    public string $editShopAccountId = '';
    public string $editCustomerId = '';
    public string $editAccountName = '';
    public string $editAccountNumber = '';
    public string $editAmount = '';
    public string $editFee = '';
    public string $editDiscount = '';
    public bool $editFeeIncludedInAmount = false;

    public ?int $cancelingId = null;
    public string $cancelReason = '';

    public function switchTab(string $tab): void
    {
        if (! in_array($tab, ['cash_in', 'cash_out'], true) || $tab === $this->tab) {
            return;
        }

        $this->tab = $tab;
        $this->resetPage();
    }

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function updatingProvider(): void
    {
        $this->resetPage();
    }

    public function updatingShopAccountId(): void
    {
        $this->resetPage();
    }

    public function updatingPaymentStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function report(): WalletLoadReport
    {
        return new WalletLoadReport($this->tab, $this->from, $this->to, $this->provider, $this->shopAccountId, $this->paymentStatusFilter, includeCancelled: true);
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'loads' => $report->query()->latest()->paginate(15),
            'totalLoaded' => $report->totalLoaded(),
            'totalFees' => $report->totalFees(),
            'totalOwed' => $report->totalOwed(),
            'providerTotals' => $report->providerTotals(),
            'allProviders' => WalletLoad::query()
                ->where('direction', $this->tab)
                ->whereNotNull('provider')
                ->distinct()
                ->orderBy('provider')
                ->pluck('provider'),
            'allShopAccounts' => ShopAccount::query()->orderBy('name')->get(),
            'shopAccountsById' => ShopAccount::query()->get()->keyBy('id'),
            'customers' => Customer::query()->orderBy('name')->get(),
            'paymentStatuses' => PaymentStatus::cases(),
            'directions' => WalletLoadDirection::cases(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'provider', 'shopAccountId', 'paymentStatusFilter']);
    }

    public function downloadReceipt(int $loadId, WalletLoadReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(WalletLoad::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($loadId));
    }

    public function markAsPaid(int $id): void
    {
        $load = WalletLoad::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($load->isCancelled(), 403);

        $remaining = (float) $load->total - (float) $load->amount_paid;

        $load->update([
            'payment_status' => PaymentStatus::Paid,
            'amount_paid' => $load->total,
        ]);

        // The rest arrived today; for a Wallet Load the way it came is known, otherwise it's not recorded.
        \App\Actions\RecordCounterPayment::handle($load, $remaining, null, null, Auth::id());

        $this->toastSuccess('Marked as paid.');
    }

    public function openAddPayment(int $id): void
    {
        $load = WalletLoad::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($load->isCancelled(), 403);

        $this->payingId = $id;
        $this->paymentAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'add-payment-form');
    }

    public function submitPayment(): void
    {
        $load = WalletLoad::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($this->payingId);
        abort_if($load->isCancelled(), 403);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $newAmountPaid = (float) $load->amount_paid + (float) $this->paymentAmount;

        $load->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $newAmountPaid >= (float) $load->total
                ? PaymentStatus::Paid
                : PaymentStatus::Partial,
        ]);
        $this->notePaymentSource($load, (float) $this->paymentAmount);

        $this->toastSuccess('Payment recorded.');
        $this->dispatch('close-modal', name: 'add-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }

    public function closePaymentForm(): void
    {
        $this->dispatch('close-modal', name: 'add-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }

    public function openEdit(int $loadId): void
    {
        abort_unless(Auth::user()->hasAccessTo('wallet-loads-edit-cancel'), 403);

        $load = WalletLoad::findOrFail($loadId);

        $this->editingId = $load->id;
        $this->editShopAccountId = (string) $load->shop_account_id;
        $this->editCustomerId = $load->customer_id !== null ? (string) $load->customer_id : '';
        $this->editAccountName = $load->account_name ?? '';
        $this->editAccountNumber = $load->account_number ?? '';
        $this->editAmount = (string) $load->amount;
        $this->editFee = (string) $load->fee;
        $this->editDiscount = (string) $load->discount;
        $this->editFeeIncludedInAmount = $load->fee_included_in_amount;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'edit-wallet-load-form');
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()->hasAccessTo('wallet-loads-edit-cancel'), 403);

        $load = WalletLoad::findOrFail($this->editingId);

        $rules = [
            'editShopAccountId' => ['required', 'integer'],
            'editCustomerId' => ['nullable', 'integer'],
            'editAmount' => ['required', 'numeric', 'min:1'],
            'editFee' => ['required', 'numeric', 'min:0'],
            'editDiscount' => ['required', 'numeric', 'min:0'],
        ];

        if ($load->direction === WalletLoadDirection::CashIn) {
            $rules['editAccountName'] = ['required', 'string', 'max:255'];
            $rules['editAccountNumber'] = ['required', 'string', 'max:20'];
        }

        $this->validate($rules);

        UpdateWalletLoad::handle($load, [
            'shop_account_id' => (int) $this->editShopAccountId,
            'customer_id' => $this->editCustomerId !== '' ? (int) $this->editCustomerId : null,
            'account_name' => $load->direction === WalletLoadDirection::CashIn ? $this->editAccountName : null,
            'account_number' => $load->direction === WalletLoadDirection::CashIn ? $this->editAccountNumber : null,
            'amount' => (float) $this->editAmount,
            'fee' => (float) $this->editFee,
            'discount' => (float) $this->editDiscount,
            'fee_included_in_amount' => $this->editFeeIncludedInAmount,
        ], Auth::id());

        $this->toastSuccess('Wallet load updated.');
        $this->dispatch('close-modal', name: 'edit-wallet-load-form');
        $this->reset(['editingId', 'editShopAccountId', 'editCustomerId', 'editAccountName', 'editAccountNumber', 'editAmount', 'editFee', 'editDiscount', 'editFeeIncludedInAmount']);
    }

    public function closeEditForm(): void
    {
        $this->dispatch('close-modal', name: 'edit-wallet-load-form');
        $this->reset(['editingId', 'editShopAccountId', 'editCustomerId', 'editAccountName', 'editAccountNumber', 'editAmount', 'editFee', 'editDiscount', 'editFeeIncludedInAmount']);
    }

    public function openCancel(int $loadId): void
    {
        abort_unless(Auth::user()->hasAccessTo('wallet-loads-edit-cancel'), 403);

        $this->cancelingId = $loadId;
        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'cancel-wallet-load-form');
    }

    public function cancelLoad(): void
    {
        abort_unless(Auth::user()->hasAccessTo('wallet-loads-edit-cancel'), 403);

        $load = WalletLoad::findOrFail($this->cancelingId);
        CancelWalletLoad::handle($load, Auth::id(), $this->cancelReason);

        $this->dispatch('close-modal', name: 'cancel-wallet-load-form');
        $this->reset(['cancelingId', 'cancelReason']);

        $this->toastSuccess('Wallet load cancelled.');
    }

    public function closeCancelForm(): void
    {
        $this->dispatch('close-modal', name: 'cancel-wallet-load-form');
        $this->reset(['cancelingId', 'cancelReason']);
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>
            Wallet Load History
            <x-slot:actions>
                @if (auth()->user()->hasAccessTo('cash-balances'))
                    <x-ui.button href="{{ route('cash-balances.index', ['tab' => 'flow']) }}" wire:navigate size="sm" variant="secondary" data-cash-balances-link>Cash &amp; Balances</x-ui.button>
                @endif
                <x-ui.button href="{{ route('wallet-loads.index') }}" wire:navigate size="sm">New Wallet Load</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot>

    {{-- Cash In / Cash Out switch the view within this page, so a segmented control, not tabs. --}}
    <div class="mb-5 inline-flex rounded-full border border-line bg-surface p-1 shadow-soft" role="group" aria-label="Cash in or cash out">
        @foreach ($directions as $option)
            <button
                type="button"
                wire:click="switchTab('{{ $option->value }}')"
                @if ($tab === $option->value) aria-pressed="true" @else aria-pressed="false" @endif
                @class([
                    'whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-semibold transition-colors',
                    'bg-primary text-on-primary shadow-soft' => $tab === $option->value,
                    'text-ink-faint hover:text-ink' => $tab !== $option->value,
                ])
            >
                {{ $option->label() }}
            </button>
        @endforeach
    </div>

    <x-ui.filter-bar :active="(int) filled($from) + (int) filled($to) + (int) filled($provider) + (int) filled($shopAccountId) + (int) filled($paymentStatusFilter)">
        <x-ui.date-range />

        <x-ui.field label="Provider" name="provider" for="provider" class="w-full sm:w-40">
            <x-ui.select wire:model.live="provider" id="provider">
                <option value="">All Providers</option>
                @foreach ($allProviders as $name)
                    <option value="{{ $name }}">{{ $name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field label="Shop Account" name="shopAccountId" for="shopAccountId" class="w-full sm:w-40">
            <x-ui.select wire:model.live="shopAccountId" id="shopAccountId">
                <option value="">All Accounts</option>
                @foreach ($allShopAccounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field label="Payment Status" name="paymentStatusFilter" for="paymentStatusFilter" class="w-full sm:w-40">
            <x-ui.select wire:model.live="paymentStatusFilter" id="paymentStatusFilter">
                <option value="">All Statuses</option>
                @foreach ($paymentStatuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        @if ($from || $to || $provider || $shopAccountId || $paymentStatusFilter)
            <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                Clear
            </x-ui.button>
        @endif
    </x-ui.filter-bar>

    <x-ui.stat-row>
        <x-ui.stat label="{{ $tab === 'cash_in' ? 'Total Loaded' : 'Cash Handed Over' }}" value="Rs {{ number_format($totalLoaded, 2) }}" />
        <x-ui.stat label="Net Service Fees" value="Rs {{ number_format($totalFees, 2) }}" />
        <x-ui.stat label="Total Owed by Customers" value="Rs {{ number_format($totalOwed, 2) }}" sub="Shown on the Udhaar screen" />
    </x-ui.stat-row>

    @if ($providerTotals->isNotEmpty())
        <h2 class="mb-2 text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">By provider</h2>
        <x-ui.stat-row>
            @foreach ($providerTotals as $row)
                <x-ui.stat :label="$row->provider" value="Rs {{ number_format($row->total, 2) }}" />
            @endforeach
        </x-ui.stat-row>
    @endif

    @if ($loads->isEmpty())
        <x-ui.empty-state
            title="No {{ $tab === 'cash_in' ? 'cash in' : 'cash out' }} wallet loads yet"
            description="Completed wallet loads will show up here."
        >
            <x-slot name="action">
                <x-ui.button href="{{ route('wallet-loads.index') }}" wire:navigate>New Wallet Load</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table stack :headers="['Receipt', 'Provider', $tab === 'cash_in' ? 'Recipient' : 'Customer', 'Amount', 'Total', 'Payment', '']">
            @foreach ($loads as $load)
                <x-ui.table-row wire:key="load-{{ $load->id }}" @class(['opacity-50' => $load->isCancelled()])>
                    <x-ui.table-cell primary class="font-semibold text-ink">{{ $load->receiptNumber() }}<span class="ui-sub">{{ $load->created_at->format('d M Y, h:i A') }}</span></x-ui.table-cell>
                    <x-ui.table-cell label="Provider">
                        <div class="flex items-center gap-2">
                            <x-ui.thumbnail :label="$load->provider" />
                            <div>
                                {{ $load->provider }}
                                <span class="ui-sub">{{ $shopAccountsById->get($load->shop_account_id)?->name ?? '—' }}</span>
                            </div>
                        </div>
                    </x-ui.table-cell>
                    @if ($tab === 'cash_in')
                        <x-ui.table-cell label="Recipient">
                            {{ $load->customer?->name ?? $load->account_name ?? '—' }}
                            @if ($load->account_number)
                                <span class="ui-sub">{{ $load->account_number }}</span>
                            @endif
                        </x-ui.table-cell>
                    @else
                        <x-ui.table-cell label="Customer">{{ $load->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    @endif
                    <x-ui.table-cell label="Amount" class="font-semibold text-ink">Rs {{ number_format($load->amount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Total" class="font-semibold text-ink">
                        Rs {{ number_format($load->total, 2) }}
                        <span class="ui-sub">Fee Rs {{ number_format($load->fee, 2) }} · Discount Rs {{ number_format($load->discount, 2) }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Payment">
                        @if ($load->isCancelled())
                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                        @elseif ($load->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($load->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                        @if (! $load->isCancelled() && $load->payment_status->value !== 'paid' && ! $load->countsAsDue())
                            <span class="ui-sub" data-not-in-dues title="Saved before Cash Out shortfalls counted as the customer's due">Not in dues (older entry)</span>
                        @endif
                        @if (! $load->isCancelled() && $load->amountOwed() > 0)
                            <span class="ui-sub font-semibold text-ink">Rs {{ number_format($load->amountOwed(), 2) }} owed</span>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.row-actions label="Actions for {{ $load->receiptNumber() }}">
                            @if (! $load->isCancelled() && $load->payment_status->value !== 'paid')
                                <x-ui.menu-item wire:click="openAddPayment({{ $load->id }})">Add Payment</x-ui.menu-item>
                                <x-ui.menu-item wire:click="markAsPaid({{ $load->id }})" confirm="Mark this wallet load as fully paid?" confirm-title="Mark as paid?" confirm-label="Mark as Paid">Mark as Paid</x-ui.menu-item>
                            @endif
                            <x-ui.menu-item wire:click="downloadReceipt({{ $load->id }})">Download</x-ui.menu-item>
                            @if (! $load->isCancelled() && auth()->user()->hasAccessTo('wallet-loads-edit-cancel'))
                                <x-ui.menu-item wire:click="openEdit({{ $load->id }})">Edit</x-ui.menu-item>
                                <x-ui.menu-divider />
                                <x-ui.menu-item danger wire:click="openCancel({{ $load->id }})">Delete</x-ui.menu-item>
                            @endif
                        </x-ui.row-actions>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $loads->links() }}
        </div>
    @endif

    <x-ui.modal name="add-payment-form" max-width="sm">
        <form wire:submit="submitPayment" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Add Payment</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field
                    :label="$tab === 'cash_in' ? 'Cash Received Now' : 'Received in Your Account Now'"
                    name="paymentAmount"
                    for="paymentAmount"
                    :help="$tab === 'cash_in' ? 'Cash the customer has just handed you' : 'Money the customer has just sent to the load\'s account'"
                >
                    <x-ui.input wire:model="paymentAmount" id="paymentAmount" type="number" min="0.01" step="0.01" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closePaymentForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submitPayment">
                    Save Payment
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="edit-wallet-load-form" max-width="lg">
        <form wire:submit="saveEdit" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Edit Wallet Load</h2>

            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Shop Account" name="editShopAccountId" for="editShopAccountId">
                    <x-ui.select wire:model="editShopAccountId" id="editShopAccountId">
                        @foreach ($allShopAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Customer" name="editCustomerId" for="editCustomerId" help="Leave blank for a walk-in">
                    <x-ui.select wire:model="editCustomerId" id="editCustomerId">
                        <option value="">Walk-in (no customer)</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                @if ($tab === 'cash_in')
                    <x-ui.field label="Account Name" name="editAccountName" for="editAccountName">
                        <x-ui.input wire:model="editAccountName" id="editAccountName" />
                    </x-ui.field>

                    <x-ui.field label="Account / Mobile Number" name="editAccountNumber" for="editAccountNumber">
                        <x-ui.input wire:model="editAccountNumber" id="editAccountNumber" />
                    </x-ui.field>
                @endif

                <x-ui.field label="Amount" name="editAmount" for="editAmount">
                    <x-ui.input wire:model="editAmount" id="editAmount" type="number" min="1" step="0.01" />
                </x-ui.field>

                <x-ui.field label="Service Charge" name="editFee" for="editFee">
                    <x-ui.input wire:model="editFee" id="editFee" type="number" min="0" step="0.01" />
                </x-ui.field>

                <x-ui.field label="Discount" name="editDiscount" for="editDiscount">
                    <x-ui.input wire:model="editDiscount" id="editDiscount" type="number" min="0" step="0.01" />
                </x-ui.field>
            </div>

            <div class="mt-4">
                <x-ui.checkbox wire:model="editFeeIncludedInAmount" label="Fee is already included in the amount above" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeEditForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveEdit">
                    Save Changes
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="cancel-wallet-load-form" max-width="sm">
        <form wire:submit="cancelLoad" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Cancel This Wallet Load?</h2>
            <p class="mt-2 text-sm text-ink-muted">
                This wallet load will be marked <strong>Cancelled</strong>. It will no longer count toward any total, report, or outstanding balance. This cannot be undone.
            </p>

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="cancelReason">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">
                    Keep Wallet Load
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelLoad" variant="danger">
                    Cancel Wallet Load
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
