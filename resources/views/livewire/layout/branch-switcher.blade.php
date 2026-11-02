<?php

use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The header's branch picker. Renders nothing for a single-branch shop.
 * A branch-restricted staff member sees their branch as a fixed label.
 * Everyone else picks a branch (new records go there, lists show only it)
 * or "All branches" (lists show everything, new records go to Main).
 */
new class extends Component
{
    public string $branchId = '';

    public function mount(): void
    {
        $this->branchId = (string) (BranchContext::current()->selectedBranchId() ?? '');
    }

    public function updatedBranchId(): void
    {
        $context = BranchContext::current();
        $user = Auth::user();

        abort_if(! $user?->shop_id || $context->restrictedBranchId($user) !== null, 403);

        $id = $this->branchId === '' ? null : (int) $this->branchId;
        $context->select($id !== null && $context->branchesFor($user->shop_id)->contains('id', $id) ? $id : null);

        $this->redirect(url()->previous(), navigate: true);
    }

    public function with(): array
    {
        $user = Auth::user();
        $context = BranchContext::current();
        $multiple = $user?->shop_id && ! $user->is_super_admin && $context->hasMultipleBranches($user->shop_id);

        return [
            'visible' => $multiple,
            'branches' => $multiple ? $context->branchesFor($user->shop_id) : collect(),
            'restricted' => $multiple && $context->restrictedBranchId($user) !== null ? $user->branch : null,
        ];
    }
}; ?>

{{-- hidden (not just empty) so a single-branch shop's header spacing is untouched --}}
<div @class(['shrink-0', 'hidden' => ! $visible])>
    @if ($visible)
        @if ($restricted)
            <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2.5 py-1.5 text-sm font-medium text-slate-700" title="You work at this branch">
                <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                {{ $restricted->name }}
            </span>
        @else
            <label for="branchSwitcher" class="sr-only">Branch</label>
            <select
                id="branchSwitcher"
                wire:model.live="branchId"
                class="rounded-md border-slate-300 py-1.5 pl-3 pr-10 text-sm font-medium text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500"
            >
                <option value="">All branches</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
        @endif
    @endif
</div>
