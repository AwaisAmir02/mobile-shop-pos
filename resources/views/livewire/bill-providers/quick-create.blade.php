<?php

use App\Actions\CreateBillProvider;
use App\Livewire\Concerns\Toasts;
use App\Models\BillCategory;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Reactive;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    #[Reactive]
    public string $defaultBillCategoryId = '';

    public string $name = '';
    public string $billCategoryId = '';
    public string $region = '';

    public function mount(): void
    {
        $this->syncDefaultBillCategory();
    }

    public function updatedDefaultBillCategoryId(): void
    {
        $this->syncDefaultBillCategory();
    }

    protected function syncDefaultBillCategory(): void
    {
        $this->billCategoryId = $this->defaultBillCategoryId !== ''
            ? $this->defaultBillCategoryId
            : (string) (BillCategory::query()->orderBy('name')->value('id') ?? '');
    }

    public function with(): array
    {
        return [
            'billCategories' => BillCategory::query()->orderBy('name')->get(),
        ];
    }

    public function save(): void
    {
        $this->validate(CreateBillProvider::rules(Auth::user()->shop_id));

        $provider = CreateBillProvider::handle($this->name, (int) $this->billCategoryId, $this->region !== '' ? $this->region : null);

        $this->toastSuccess('Bill provider added.');
        $this->dispatch('close-modal', name: 'quick-create-bill-provider');
        $this->dispatch('bill-provider-created', providerId: $provider->id, billCategoryId: $provider->bill_category_id);
        $this->reset(['name', 'region']);
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-bill-provider');
        $this->reset(['name', 'region']);
        $this->syncDefaultBillCategory();
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-bill-provider" max-width="md">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">New Bill Provider</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Category" name="billCategoryId" for="quickBillProviderCategory">
                    <x-ui.select wire:model="billCategoryId" id="quickBillProviderCategory">
                        @foreach ($billCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Provider Name" name="name" for="quickBillProviderName" help="e.g. LESCO, K-Electric">
                        <x-ui.input wire:model="name" id="quickBillProviderName" autofocus />
                    </x-ui.field>

                    <x-ui.field label="Region / Province" name="region" for="quickBillProviderRegion" help="Optional">
                        <x-ui.input wire:model="region" id="quickBillProviderRegion" placeholder="e.g. Punjab" />
                    </x-ui.field>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="close">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    Add Provider
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
