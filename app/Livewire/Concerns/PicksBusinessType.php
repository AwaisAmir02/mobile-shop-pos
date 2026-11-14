<?php

namespace App\Livewire\Concerns;

use App\Enums\BusinessCategory;
use App\Enums\BusinessGroup;
use Illuminate\Validation\Rule;

/**
 * The two-step business picker on signup: a group, then a sub-type in it.
 * Only $businessCategory is saved; $businessGroup just drives the UI.
 */
trait PicksBusinessType
{
    public string $businessGroup = '';

    public function updatedBusinessGroup(): void
    {
        if (BusinessGroup::tryFrom($this->businessGroup) === null) {
            $this->businessGroup = '';
        }

        $category = BusinessCategory::tryFrom($this->businessCategory);

        if ($category === null || $category->group()?->value !== $this->businessGroup) {
            $this->businessCategory = '';
        }
    }

    public function updatedBusinessCategory(): void
    {
        $group = BusinessCategory::tryFrom($this->businessCategory)?->group();

        if ($group !== null) {
            $this->businessGroup = $group->value;
        }
    }

    protected function businessCategoryRules(): array
    {
        return ['required', Rule::in(BusinessGroup::signupValues())];
    }

    protected function businessCategoryMessages(): array
    {
        return [
            'businessCategory.required' => $this->businessGroup === '' ? 'Choose what kind of business you are.' : 'Choose the type of business that fits you best.',
            'businessCategory.in' => 'Choose one of the business types listed.',
        ];
    }
}
