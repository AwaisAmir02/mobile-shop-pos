<?php

namespace App\Livewire\Concerns;

use App\Enums\BusinessCategory;
use App\Enums\BusinessGroup;
use Illuminate\Validation\Rule;

/**
 * The two-step business picker on signup: a group, then a sub-type in it,
 * and for a service business any related trade it also runs (a salon that is
 * also a spa: BusinessCategory::combinesWith). $businessCategory and
 * $businessAddons are saved; $businessGroup just drives the UI.
 */
trait PicksBusinessType
{
    public string $businessGroup = '';

    /** @var list<string> related trades also run, only from BusinessCategory::combinesWith() */
    public array $businessAddons = [];

    /** @return list<string> the addons the chosen category allows */
    protected function chosenBusinessAddons(): array
    {
        return BusinessCategory::tryFrom($this->businessCategory)?->cleanAddons($this->businessAddons) ?? [];
    }

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
        $this->businessAddons = $this->chosenBusinessAddons();

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
