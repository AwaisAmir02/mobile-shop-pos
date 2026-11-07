<?php

use App\Actions\StoreConnections;
use App\Livewire\Concerns\Toasts;
use App\Models\Product;
use App\Models\ShopAccount;
use App\Models\StoreConnection;
use App\Models\StoreProduct;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use Toasts, WithPagination;

    public string $newName = '';

    public string $search = '';

    public ?int $selectedId = null;

    public int $safetyBuffer = 0;

    public bool $priceSync = false;

    public string $shopAccountId = '';

    /** Shown once, then gone: only a hash of the token is kept. */
    public ?string $shownToken = null;

    public ?string $shownSecret = null;

    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-integrations') && Auth::user()->shop?->online_store_enabled, 403);
    }

    public function mount(): void
    {
        $this->select(StoreConnection::notRemoved()->orderBy('id')->value('id'));
    }

    public function select(?int $id): void
    {
        $connection = $id === null ? null : StoreConnection::notRemoved()->find($id);

        $this->selectedId = $connection?->id;
        $this->safetyBuffer = $connection?->safety_buffer ?? 0;
        $this->priceSync = $connection?->price_sync ?? false;
        $this->shopAccountId = (string) ($connection?->shop_account_id ?? '');
        $this->resetPage();
    }

    protected function selected(): StoreConnection
    {
        return StoreConnection::notRemoved()->findOrFail($this->selectedId);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createConnection(): void
    {
        $this->validate(['newName' => ['required', 'string', 'max:100']], ['newName.required' => 'Name this store, for example its web address.']);

        $created = StoreConnections::create(Auth::user()->shop_id, $this->newName, Auth::id());

        $this->shownToken = $created['token'];
        $this->shownSecret = $created['secret'];
        $this->reset('newName');
        $this->select($created['connection']->id);
        $this->toastSuccess('Store connected. Copy the token and secret now.');
    }

    public function newToken(): void
    {
        $this->shownToken = StoreConnections::issueToken($this->selected());
        $this->shownSecret = null;
        $this->toastSuccess('New API token made. The old one has stopped working.');
    }

    public function newSecret(): void
    {
        $this->shownSecret = StoreConnections::rotateSecret($this->selected());
        $this->shownToken = null;
        $this->toastSuccess('New signing secret made. The old one keeps working for '.StoreConnections::SECRET_OVERLAP_HOURS.' hours.');
    }

    public function hideCredentials(): void
    {
        $this->reset('shownToken', 'shownSecret');
    }

    public function pause(): void
    {
        StoreConnections::pause($this->selected());
        $this->toastSuccess('Connection paused. The store should stop selling.');
    }

    public function resume(): void
    {
        StoreConnections::resume($this->selected());
        $this->toastSuccess('Connection resumed.');
    }

    public function removeConnection(): void
    {
        StoreConnections::remove($this->selected());
        $this->hideCredentials();
        $this->select(StoreConnection::notRemoved()->orderBy('id')->value('id'));
        $this->toastSuccess('Connection removed. Its token no longer works.');
    }

    public function saveSettings(): void
    {
        $this->validate([
            'safetyBuffer' => ['required', 'integer', 'min:0', 'max:100000'],
            'shopAccountId' => ['nullable', 'integer', Rule::exists('shop_accounts', 'id')->where('shop_id', Auth::user()->shop_id)],
        ]);

        StoreConnections::updateSettings($this->selected(), $this->priceSync, $this->safetyBuffer, $this->shopAccountId !== '' ? (int) $this->shopAccountId : null);
        $this->toastSuccess('Settings saved.');
    }

    public function togglePublished(int $productId): void
    {
        $connection = $this->selected();
        $product = Product::findOrFail($productId);
        $row = StoreProduct::where('store_connection_id', $connection->id)->where('product_id', $product->id)->first();

        try {
            $row?->published ? StoreConnections::unpublish($connection, $product) : StoreConnections::publish($connection, collect([$product]));
        } catch (ValidationException $e) {
            $this->toastError($e->validator->errors()->first());
        }
    }

    public function publishAll(): void
    {
        $connection = $this->selected();
        $count = 0;

        Product::publishableOnline()->orderBy('id')->chunkById(200, function ($products) use ($connection, &$count) {
            StoreConnections::publish($connection, $products);
            $count += $products->count();
        });

        $this->toastSuccess("{$count} products published.");
    }

    public function saveSku(int $productId, string $sku): void
    {
        $this->resetErrorBag();

        try {
            StoreConnections::setExternalSku($this->selected(), Product::findOrFail($productId), mb_substr($sku, 0, 100));
        } catch (ValidationException $e) {
            $this->toastError($e->validator->errors()->first());
        }
    }

    public function with(): array
    {
        $connection = $this->selectedId === null ? null : StoreConnection::notRemoved()->find($this->selectedId);

        $products = $connection === null ? null : Product::publishableOnline()
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->paginate(25);

        return [
            'connections' => StoreConnection::notRemoved()->orderBy('id')->get(),
            'accounts' => ShopAccount::query()->orderBy('name')->get(),
            'connection' => $connection,
            'activeToken' => $connection?->tokens()->whereNull('revoked_at')->latest('id')->first(),
            'products' => $products,
            'rows' => $connection === null ? collect() : StoreProduct::where('store_connection_id', $connection->id)->whereIn('product_id', $products->pluck('id'))->get()->keyBy('product_id'),
            'publishedCount' => $connection === null ? 0 : StoreProduct::where('store_connection_id', $connection->id)->where('published', true)->count(),
            'excludedCount' => Product::whereNull('archived_at')->count() - Product::publishableOnline()->count(),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-ui.card title="Online store" description="Connect your own online store. It reads your published products and live stock, and sends its orders here. Stock in HisabOKitab is always the true figure; the store can't change it.">
        <form wire:submit="createConnection" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                <x-ui.field label="New connection" name="newName" for="newStoreName">
                    <x-ui.input wire:model="newName" id="newStoreName" placeholder="myshop.pk" maxlength="100" />
                </x-ui.field>
            </div>
            <x-ui.button type="submit">Connect a store</x-ui.button>
        </form>

        @if ($connections->count() > 1)
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($connections as $option)
                    <x-ui.button size="sm" :variant="$option->id === $selectedId ? 'primary' : 'secondary'" wire:click="select({{ $option->id }})" wire:key="connection-{{ $option->id }}">{{ $option->name }}</x-ui.button>
                @endforeach
            </div>
        @endif

        @if ($connections->isEmpty())
            <p class="mt-4 text-sm text-slate-500">No store connected yet.</p>
        @endif
    </x-ui.card>

    @if ($shownToken || $shownSecret)
        <x-ui.alert variant="warning">
            <p class="font-semibold">Copy these now. They won't be shown again.</p>
            @if ($shownToken)
                <p class="mt-2 text-sm">API token</p>
                <code class="block break-all text-xs">{{ $shownToken }}</code>
            @endif
            @if ($shownSecret)
                <p class="mt-2 text-sm">Signing secret</p>
                <code class="block break-all text-xs">{{ $shownSecret }}</code>
            @endif
            <div class="mt-3">
                <x-ui.button size="sm" variant="secondary" wire:click="hideCredentials">I've copied them</x-ui.button>
            </div>
        </x-ui.alert>
    @endif

    @if ($connection)
        <x-ui.card :title="$connection->name" description="Give your store's developer the API token, the signing secret and the connection ID.">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-1 text-sm text-slate-600">
                    <div class="flex items-center gap-2">
                        <x-ui.badge :variant="$connection->isLive() ? 'success' : 'warning'">{{ $connection->isLive() ? 'Active' : 'Paused' }}</x-ui.badge>
                        <code class="text-xs">{{ $connection->public_id }}</code>
                    </div>
                    <p>API token: <code class="text-xs">{{ $activeToken?->token_prefix }}…</code>{{ $activeToken?->last_used_at ? ', last used '.$activeToken->last_used_at->diffForHumans() : ', not used yet' }}</p>
                    <p>{{ number_format($publishedCount) }} products published</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button size="sm" variant="secondary" wire:click="newToken" wire:confirm="Make a new API token? The current one stops working straight away.">New token</x-ui.button>
                    <x-ui.button size="sm" variant="secondary" wire:click="newSecret" wire:confirm="Make a new signing secret? The current one keeps working for {{ StoreConnections::SECRET_OVERLAP_HOURS }} hours.">New secret</x-ui.button>
                    @if ($connection->status === StoreConnection::ACTIVE)
                        <x-ui.button size="sm" variant="secondary" wire:click="pause" wire:confirm="Pause this connection? The store should stop selling until you resume.">Pause</x-ui.button>
                    @else
                        <x-ui.button size="sm" variant="secondary" wire:click="resume">Resume</x-ui.button>
                    @endif
                    <x-ui.button size="sm" variant="danger" wire:click="removeConnection" wire:confirm="Remove this connection for good? Its token stops working and it can't be brought back.">Remove</x-ui.button>
                </div>
            </div>

            <form wire:submit="saveSettings" class="mt-6 grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-2">
                <x-ui.field label="Safety buffer" name="safetyBuffer" for="storeSafetyBuffer">
                    <x-ui.input wire:model="safetyBuffer" id="storeSafetyBuffer" type="number" min="0" />
                    <p class="mt-1 text-xs text-slate-500">Keep this many of each product back from the store, to reduce overselling.</p>
                </x-ui.field>
                <label class="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="priceSync" class="mt-1 rounded border-slate-300">
                    <span>
                        Send prices to the store
                        <span class="block text-xs text-slate-500">Off means the store sets its own prices. On sends the price of the next batch to sell, which can change when a batch runs out.</span>
                    </span>
                </label>
                {{-- Keyed by connection so switching connection re-renders the select's label. --}}
                <div wire:key="store-account-{{ $connection->id }}">
                    <x-ui.field label="Prepaid money goes to" name="shopAccountId" for="storeShopAccount">
                        <x-ui.select wire:model="shopAccountId" id="storeShopAccount">
                            <option value="">Choose when accepting each order</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }} · {{ $account->provider_type }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>
                <div class="sm:col-span-2">
                    <x-ui.button type="submit" size="sm">Save settings</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card title="Published products" description="Only ticked products reach the store. The store's own code for a product is optional.">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex-1">
                    <x-ui.input wire:model.live.debounce.300ms="search" placeholder="Search products" aria-label="Search products" />
                </div>
                <x-ui.button size="sm" variant="secondary" wire:click="publishAll" wire:confirm="Publish every product that can be sold online?">Publish all</x-ui.button>
            </div>

            @if ($excludedCount > 0)
                <p class="mt-3 text-xs text-slate-500">{{ number_format($excludedCount) }} products aren't listed because they're sold by weight or length, IMEI-tracked, or SIMs.</p>
            @endif

            <div class="mt-4 divide-y divide-slate-100">
                @forelse ($products as $product)
                    @php($row = $rows->get($product->id))
                    <div class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="store-product-{{ $product->id }}">
                        <label class="flex items-center gap-3 text-sm">
                            <input type="checkbox" @checked($row?->published) wire:click="togglePublished({{ $product->id }})" class="rounded border-slate-300">
                            <span>
                                <span class="font-medium text-slate-900">{{ $product->name }}</span>
                                <span class="block text-xs text-slate-500">{{ Quantity::plain($product->stock_quantity) }} in stock</span>
                            </span>
                        </label>
                        <div class="sm:w-56">
                            <x-ui.input value="{{ $row?->external_sku }}" placeholder="Store's code (optional)" aria-label="Store's code for {{ $product->name }}" maxlength="100" wire:change="saveSku({{ $product->id }}, $event.target.value)" />
                        </div>
                    </div>
                @empty
                    <p class="py-3 text-sm text-slate-500">No products to show.</p>
                @endforelse
            </div>

            <div class="mt-4">{{ $products->links() }}</div>
        </x-ui.card>
    @endif
</div>
