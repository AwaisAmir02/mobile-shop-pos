<?php

use App\Actions\StartLedger;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\LedgerOpening;
use App\Ledger\Line;
use App\Models\ShopAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Set Up the Ledger')] class extends Component
{
    public string $cash = '';

    /** @var array<int, string> */
    public array $accountBalances = [];

    /** Worked out once on opening: it reads every record. */
    public array $fromRecords = [];
    public float $inventory = 0.0;

    public function boot(): void
    {
        abort_unless(Auth::user()->is_owner && Auth::user()->shop?->ledger_enabled, 403);
    }

    public function mount(): void
    {
        $shop = Auth::user()->shop;

        if ($shop->ledger_ready_at !== null && $shop->ledger_frozen_at === null) {
            $this->redirectRoute('ledger.trial-balance', navigate: true);

            return;
        }

        $this->inventory = LedgerOpening::inventoryValue($shop->id);
        $totals = [];

        foreach (LedgerOpening::fromBuilders($shop) as $line) {
            $totals[$line['account']] = ($totals[$line['account']] ?? 0) + $line['paise'];
        }

        $this->fromRecords = $totals;
    }

    public function with(): array
    {
        $shop = Auth::user()->shop;
        $typed = Line::paise($this->cash) + collect($this->accountBalances)->sum(fn ($amount) => Line::paise($amount));
        $total = array_sum($this->fromRecords) + Line::paise($this->inventory) + $typed;

        return [
            'shop' => $shop,
            'accounts' => ShopAccount::query()->orderBy('name')->get(),
            'lines' => collect($this->fromRecords)->filter()->map(fn (int $paise, string $code) => ['name' => Chart::ACCOUNTS[$code][0], 'paise' => $paise])->all(),
            'openingBalance' => -$total / 100,
            'noCost' => DB::table('products')->where('shop_id', $shop->id)->where('stock_quantity', '>', 0)->whereNull('cost_price')->count(),
            'interrupted' => $shop->ledger_started_at !== null && $shop->ledger_ready_at === null && $shop->ledger_frozen_at === null,
        ];
    }

    public function start(): void
    {
        $this->validate([
            'cash' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'accountBalances.*' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ], [], ['cash' => 'cash in hand', 'accountBalances.*' => 'balance']);

        $shopId = Auth::user()->shop_id;
        $ids = ShopAccount::query()->where('shop_id', $shopId)->pluck('id')->all();
        $balances = array_filter($this->accountBalances, fn ($amount, $id) => in_array((int) $id, $ids, true) && $amount !== '', ARRAY_FILTER_USE_BOTH);

        StartLedger::handle(Auth::user()->shop, ['cash' => $this->cash === '' ? 0 : $this->cash, 'accounts' => $balances]);
        Auth::user()->unsetRelation('shop');

        session()->flash('status', 'The ledger has started. New records post to it from now on.');

        $this->redirectRoute('ledger.trial-balance', navigate: true);
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Set Up the Ledger</h1>
    </x-slot>

    <x-ui.error :messages="$errors->get('ledger')" class="mb-4" />

    @if ($shop->ledger_frozen_at)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            This starts a new ledger from today's figures. The frozen one is kept but stays out of these reports.
        </div>
    @elseif ($interrupted)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            An earlier setup didn't finish. Nothing was posted, so you can safely run it again.
        </div>
    @endif

    <p class="mb-4 text-sm text-slate-600">From here on, new records post to the ledger automatically. Your other screens, dues and reports don't change. Past sales and expenses aren't replayed: the ledger starts from these balances today.</p>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card title="What you have today" description="Only you know these. The records can't tell.">
            <div class="space-y-4">
                <x-ui.field label="Cash in Hand" name="cash" for="openingCash">
                    <x-ui.input wire:model.live.debounce.400ms="cash" id="openingCash" type="number" min="0" step="0.01" />
                </x-ui.field>
                @foreach ($accounts as $account)
                    <x-ui.field :label="$account->name" :name="'accountBalances.'.$account->id" :for="'openingAccount'.$account->id">
                        <x-ui.input wire:model.live.debounce.400ms="accountBalances.{{ $account->id }}" :id="'openingAccount'.$account->id" type="number" min="0" step="0.01" />
                    </x-ui.field>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card title="Opening balances">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Inventory at cost</dt><dd>Rs {{ number_format($inventory, 2) }}</dd></div>
                @foreach ($lines as $line)
                    <div class="flex justify-between"><dt class="text-slate-500">{{ $line['name'] }}</dt><dd>Rs {{ number_format(abs($line['paise']) / 100, 2) }} {{ $line['paise'] < 0 ? 'Cr' : 'Dr' }}</dd></div>
                @endforeach
                <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold text-slate-900"><dt>Opening Balances (the difference)</dt><dd>Rs {{ number_format(abs($openingBalance), 2) }} {{ $openingBalance < 0 ? 'Cr' : 'Dr' }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-slate-500">Inventory uses each batch's cost. Stock no batch covers uses the product's current cost.</p>
            @if ($noCost > 0)
                <p class="mt-2 text-sm text-amber-700">{{ number_format($noCost) }} {{ str('product')->plural($noCost) }} in stock {{ $noCost === 1 ? 'has' : 'have' }} no cost, so {{ $noCost === 1 ? 'it counts' : 'they count' }} as Rs 0 here. Add a cost first if you can.</p>
            @endif
            <x-ui.button type="button" class="mt-5" wire:click="start" wire:confirm="Start the ledger with these balances?" wire:loading.attr="disabled" wire:target="start">Start Ledger</x-ui.button>
        </x-ui.card>
    </div>
</div>
