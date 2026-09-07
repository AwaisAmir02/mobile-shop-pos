<?php

use App\Actions\CreateBillCategory;
use App\Livewire\Concerns\Toasts;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public string $name = '';

    public function save(): void
    {
        $this->validate(CreateBillCategory::rules(Auth::user()->shop_id));

        $category = CreateBillCategory::handle($this->name);

        $this->toastSuccess('Bill category added.');
        $this->dispatch('close-modal', name: 'quick-create-bill-category');
        $this->dispatch('bill-category-created', categoryId: $category->id);
        $this->reset(['name']);
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-bill-category');
        $this->reset(['name']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-bill-category" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">New Bill Category</h2>

            <div class="mt-5">
                <x-ui.field label="Category Name" name="name" for="quickBillCategoryName" help="e.g. Electricity, Gas, Water">
                    <x-ui.input wire:model="name" id="quickBillCategoryName" autofocus />
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
