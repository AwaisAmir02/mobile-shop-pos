<?php

use App\Enums\ConnectionStatus;
use App\Enums\PaymentGateway;
use App\Livewire\Concerns\Toasts;
use App\Models\PaymentGatewayConnection;
use App\Models\ShopAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

/**
 * The shop's own JazzCash merchant account, for the Pay button on its
 * customers' ledger pages. JazzCash has no "check these details" call, so the
 * details are saved as given and the first paid payment proves them.
 */
new class extends Component
{
    use Toasts;

    public bool $editing = false;
    public string $merchantId = '';
    public string $password = '';
    public string $integritySalt = '';
    public string $environment = 'live';
    public string $shopAccountId = '';

    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('shop-accounts'), 403);
    }

    protected function connection(): ?PaymentGatewayConnection
    {
        return PaymentGatewayConnection::where('gateway', PaymentGateway::JazzCash->value)->first();
    }

    public function edit(): void
    {
        $connection = $this->connection();
        $this->editing = true;
        $this->merchantId = (string) ($connection?->credential('merchant_id') ?? '');
        $this->password = '';
        $this->integritySalt = '';
        $this->environment = $connection?->credential('environment') ?? 'live';
        $this->shopAccountId = (string) ($connection?->shop_account_id ?? '');
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $existing = $this->connection();
        $keep = fn (string $key, string $typed) => $typed !== '' ? $typed : (string) ($existing?->credential($key) ?? '');

        $this->validate([
            'merchantId' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9]+$/'],
            'password' => [$existing?->credential('password') ? 'nullable' : 'required', 'string', 'max:100'],
            'integritySalt' => [$existing?->credential('integrity_salt') ? 'nullable' : 'required', 'string', 'max:100'],
            'environment' => ['required', Rule::in(['live', 'sandbox'])],
            'shopAccountId' => ['nullable', 'integer', Rule::exists('shop_accounts', 'id')->where('shop_id', Auth::user()->shop_id)],
        ], [], ['merchantId' => 'Merchant ID', 'integritySalt' => 'Integrity Salt', 'shopAccountId' => 'account']);

        $connection = $existing ?? new PaymentGatewayConnection(['gateway' => PaymentGateway::JazzCash]);
        $connection->credentials = [
            'merchant_id' => trim($this->merchantId),
            'password' => $keep('password', trim($this->password)),
            'integrity_salt' => $keep('integrity_salt', trim($this->integritySalt)),
            'environment' => $this->environment,
        ];
        $connection->fill([
            'shop_account_id' => $this->shopAccountId !== '' ? (int) $this->shopAccountId : null,
            'status' => ConnectionStatus::Connected,
            'connected_by' => Auth::id(),
        ])->save();

        $this->editing = false;
        $this->toastSuccess('JazzCash saved. Customers now see a Pay button on their ledger link.');
    }

    public function disconnect(): void
    {
        $this->connection()?->update(['status' => ConnectionStatus::Revoked, 'credentials' => []]);
        $this->toastSuccess('JazzCash disconnected. The Pay button is gone from customers\' links.');
    }

    public function with(): array
    {
        return [
            'connection' => $this->connection(),
            'accounts' => ShopAccount::query()->orderBy('name')->get(),
            'returnUrl' => route('payments.jazzcash.return'),
            'easypaisa' => PaymentGateway::Easypaisa,
        ];
    }
}; ?>

<x-ui.card title="Customer payments" description="Let customers pay what they owe from their own ledger link, into your own JazzCash merchant account. The money goes straight to you; we never hold it." class="mt-6" data-customer-payments>
    <div class="divide-y divide-line">
        <div class="pb-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-semibold text-ink">JazzCash</p>
                    @if ($connection?->isActive())
                        <p class="text-sm text-ink-faint">
                            Merchant {{ $connection->credential('merchant_id') }}{{ $connection->credential('environment') === 'sandbox' ? ' · test (sandbox)' : '' }}
                            · {{ $connection->last_paid_at ? 'last payment '.$connection->last_paid_at->diffForHumans() : 'no payment received yet' }}
                            @if ($connection->shopAccount) · lands in {{ $connection->shopAccount->name }} @endif
                        </p>
                    @else
                        <p class="text-sm text-ink-faint">Not connected</p>
                    @endif
                </div>
                <div class="flex gap-2">
                    @if ($connection?->isActive())
                        <x-ui.badge variant="success">Connected</x-ui.badge>
                        @unless ($editing)
                            <x-ui.button size="sm" variant="secondary" wire:click="edit">Change</x-ui.button>
                            <x-ui.button size="sm" variant="secondary" wire:click="disconnect" confirm="Disconnect JazzCash? Customers can no longer pay from their link.">Disconnect</x-ui.button>
                        @endunless
                    @elseif (! $editing)
                        <x-ui.button size="sm" wire:click="edit">Connect</x-ui.button>
                    @endif
                </div>
            </div>

            @if ($editing)
                <form wire:submit="save" class="mt-4 space-y-4 rounded-xl border border-line bg-surface-sunken/50 p-4">
                    <p class="text-sm text-ink-muted">You need your own JazzCash merchant account (apply at jazzcash.com.pk; for a test account, sign up at sandbox.jazzcash.com.pk). In the JazzCash merchant portal, copy your Merchant ID, Password and Integrity Salt.</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Merchant ID" name="merchantId" for="merchantId">
                            <x-ui.input wire:model="merchantId" id="merchantId" autocomplete="off" />
                        </x-ui.field>
                        <x-ui.field label="Account" name="environment" for="environment">
                            <x-ui.select wire:model="environment" id="environment">
                                <option value="live">Live</option>
                                <option value="sandbox">Sandbox (test)</option>
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.field label="Password" name="password" for="jcPassword" :help="$connection?->credential('password') ? 'Leave blank to keep the saved one.' : null">
                            <x-ui.password-input wire:model="password" id="jcPassword" autocomplete="off" />
                        </x-ui.field>
                        <x-ui.field label="Integrity Salt" name="integritySalt" for="integritySalt" :help="$connection?->credential('integrity_salt') ? 'Leave blank to keep the saved one.' : null">
                            <x-ui.password-input wire:model="integritySalt" id="integritySalt" autocomplete="off" />
                        </x-ui.field>
                        <x-ui.field label="Payments land in" name="shopAccountId" for="shopAccountId" help="Your JazzCash shop account, so Cash & Balances shows the money" class="sm:col-span-2">
                            <x-ui.select wire:model="shopAccountId" id="shopAccountId">
                                <option value="">Not recorded</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }} · {{ $account->provider_type }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>
                    <p class="text-xs text-ink-faint">In the JazzCash portal, set the IPN / return URL to <span class="font-mono text-ink-muted">{{ $returnUrl }}</span>, so a payment is recorded even if the customer closes the page.</p>
                    <div class="flex gap-2">
                        <x-ui.button type="submit">Save</x-ui.button>
                        <x-ui.button type="button" variant="secondary" wire:click="$set('editing', false)">Cancel</x-ui.button>
                    </div>
                </form>
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 pt-4">
            <div>
                <p class="font-semibold text-ink">{{ $easypaisa->label() }}</p>
                <p class="text-sm text-ink-faint">{{ $easypaisa->unavailableReason() }}</p>
            </div>
            <x-ui.badge variant="neutral">Coming later</x-ui.badge>
        </div>
    </div>
</x-ui.card>
