<?php

use App\Actions\CreateServiceItem;
use App\Livewire\Concerns\Toasts;
use App\Models\ServiceCategory;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Reactive;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    #[Reactive]
    public string $defaultServiceCategoryId = '';

    public string $name = '';
    public string $serviceCategoryId = '';

    public function mount(): void
    {
        $this->syncDefaultServiceCategory();
    }

    public function updatedDefaultServiceCategoryId(): void
    {
        $this->syncDefaultServiceCategory();
    }

    protected function syncDefaultServiceCategory(): void
    {
        $this->serviceCategoryId = $this->defaultServiceCategoryId !== ''
            ? $this->defaultServiceCategoryId
            : (string) (ServiceCategory::query()->orderBy('name')->value('id') ?? '');
    }

    public function with(): array
    {
        return [
            'serviceCategories' => ServiceCategory::query()->orderBy('name')->get(),
        ];
    }

    public function save(): void
    {
        $this->validate(CreateServiceItem::rules(Auth::user()->shop_id));

        $item = CreateServiceItem::handle($this->name, (int) $this->serviceCategoryId);

        $this->toastSuccess('Service item added.');
        $this->dispatch('close-modal', name: 'quick-create-service-item');
        $this->dispatch('service-item-created', itemId: $item->id, serviceCategoryId: $item->service_category_id);
        $this->reset(['name']);
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-service-item');
        $this->reset(['name']);
        $this->syncDefaultServiceCategory();
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-service-item" max-width="md">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">New Service Item</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Category" name="serviceCategoryId" for="quickServiceItemCategory">
                    <x-ui.select wire:model="serviceCategoryId" id="quickServiceItemCategory">
                        @foreach ($serviceCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Item Name" name="name" for="quickServiceItemName" help="e.g. Screen Protector Install, Google Play Gift Card">
                    <x-ui.input wire:model="name" id="quickServiceItemName" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="close">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    Add Item
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
