<?php

use App\Livewire\Concerns\Toasts;
use App\Models\MainCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public string $name = '';

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('main_categories', 'name')->where('shop_id', Auth::user()->shop_id)],
        ]);

        $category = MainCategory::create([
            'name' => $this->name,
            'slug' => MainCategory::uniqueSlugFor($this->name),
        ]);

        $this->toastSuccess('Main category added.');
        $this->dispatch('close-modal', name: 'quick-create-main-category');
        $this->dispatch('main-category-created', slug: $category->slug);
        $this->reset(['name']);
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-main-category');
        $this->reset(['name']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-main-category" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">New Main Category</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Main Category Name" name="name" for="quickMainCategoryName">
                    <x-ui.input wire:model="name" id="quickMainCategoryName" placeholder="e.g. Laptops" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="close">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    Add Main Category
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
