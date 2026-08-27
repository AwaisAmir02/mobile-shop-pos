<?php

use App\Actions\CreateAccessoryCategory;
use App\Livewire\Concerns\Toasts;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public string $name = '';

    public function save(): void
    {
        $this->validate(CreateAccessoryCategory::rules(Auth::user()->shop_id));

        $category = CreateAccessoryCategory::handle($this->name);

        $this->toastSuccess('Accessory category added.');
        $this->dispatch('close-modal', name: 'quick-create-accessory-category');
        $this->dispatch('accessory-category-created', categoryName: $category->name);
        $this->reset(['name']);
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-accessory-category');
        $this->reset(['name']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-accessory-category" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">New Accessory Category</h2>

            <div class="mt-5">
                <x-ui.field label="Category Name" name="name" for="quickCategoryName">
                    <x-ui.input wire:model="name" id="quickCategoryName" placeholder="e.g. Charger" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="close">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    Add Category
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
