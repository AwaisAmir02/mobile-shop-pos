<?php

use App\Actions\CreateServiceCategory;
use App\Livewire\Concerns\Toasts;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public string $name = '';

    public function save(): void
    {
        $this->validate(CreateServiceCategory::rules(Auth::user()->shop_id));

        $category = CreateServiceCategory::handle($this->name);

        $this->toastSuccess('Service category added.');
        $this->dispatch('close-modal', name: 'quick-create-service-category');
        $this->dispatch('service-category-created', categoryId: $category->id);
        $this->reset(['name']);
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-service-category');
        $this->reset(['name']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-service-category" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">New Service Category</h2>

            <div class="mt-5">
                <x-ui.field label="Category Name" name="name" for="quickServiceCategoryName" help="e.g. Services, Installations, Downloads">
                    <x-ui.input wire:model="name" id="quickServiceCategoryName" autofocus />
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
