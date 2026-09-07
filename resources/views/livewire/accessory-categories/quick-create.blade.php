<?php

use App\Actions\CreateAccessoryCategory;
use App\Livewire\Concerns\Toasts;
use App\Models\MainCategory;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Reactive;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    #[Reactive]
    public string $defaultMainCategorySlug = '';

    public string $name = '';
    public string $mainCategoryId = '';

    public function mount(): void
    {
        MainCategory::ensureDefaultsExist();
        $this->syncDefaultMainCategory();
    }

    public function updatedDefaultMainCategorySlug(): void
    {
        $this->syncDefaultMainCategory();
    }

    protected function syncDefaultMainCategory(): void
    {
        $this->mainCategoryId = (string) (
            MainCategory::where('slug', $this->defaultMainCategorySlug)->value('id')
            ?? MainCategory::query()->orderBy('name')->value('id')
            ?? ''
        );
    }

    public function with(): array
    {
        return [
            'mainCategories' => MainCategory::query()->orderBy('name')->get(),
        ];
    }

    public function save(): void
    {
        $this->validate(CreateAccessoryCategory::rules(Auth::user()->shop_id));

        $category = CreateAccessoryCategory::handle($this->name, (int) $this->mainCategoryId);

        $this->toastSuccess('Sub-category added.');
        $this->dispatch('close-modal', name: 'quick-create-accessory-category');
        $this->dispatch('accessory-category-created', categoryName: $category->name);
        $this->reset(['name']);
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-accessory-category');
        $this->reset(['name']);
        $this->syncDefaultMainCategory();
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-accessory-category" max-width="md">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">New Sub-Category</h2>

            <div class="mt-5 space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Main Category" name="mainCategoryId" for="quickSubCategoryMainCategory">
                        <x-ui.select wire:model="mainCategoryId" id="quickSubCategoryMainCategory">
                            @foreach ($mainCategories as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Sub-Category Name" name="name" for="quickCategoryName">
                        <x-ui.input wire:model="name" id="quickCategoryName" placeholder="e.g. Charger" autofocus />
                    </x-ui.field>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="close">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    Add Sub-Category
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
