<?php

use App\Livewire\Concerns\Toasts;
use App\Services\ProductImportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Three steps, each independently gated server-side (not just the button
 * that opens the modal) via Auth::user()->canImportProducts():
 *   1. upload   — parse + validate, zero database writes.
 *   2. preview  — shown only once the file is fully valid; only aggregate
 *      counts/lists are kept in public properties (capped), never the full
 *      parsed rows, so a 500-row file doesn't bloat every request payload.
 *   3. confirm  — re-reads and re-validates the STORED file from scratch
 *      (never trusts the preview's own numbers) and writes everything
 *      inside one DB transaction.
 */
new class extends Component
{
    use Toasts, WithFileUploads;

    public $importFile = null;

    public string $storedPath = '';

    public string $step = 'upload'; // upload | preview | done

    public bool $processing = false;

    // Preview summary — aggregates and capped lists only, see ProductImportService::summarize().
    public array $fileErrors = [];

    public int $fileErrorsMore = 0;

    public array $rowErrors = [];

    public int $rowErrorsMore = 0;

    public int $toCreateCount = 0;

    public int $toSkipCount = 0;

    public array $skippedLines = [];

    public int $skippedLinesMore = 0;

    public array $newMainCategoryNames = [];

    public array $newSubCategoryNames = [];

    public array $newBrandNames = [];

    public array $similarWarnings = [];

    // Result summary, shown after a successful confirm.
    public int $createdCount = 0;

    public int $skippedCount = 0;

    public int $createdMainCategories = 0;

    public int $createdSubCategories = 0;

    public int $createdBrands = 0;

    protected function guard(): void
    {
        abort_unless(Auth::user()->canImportProducts(), 403);
    }

    #[On('request-open-product-import')]
    public function openModal(): void
    {
        $this->guard();
        $this->resetState();
        $this->dispatch('open-modal', name: 'product-import');
    }

    protected function resetState(): void
    {
        $this->deleteStoredFile();

        $this->reset([
            'importFile', 'storedPath', 'processing',
            'fileErrors', 'fileErrorsMore', 'rowErrors', 'rowErrorsMore',
            'toCreateCount', 'toSkipCount', 'skippedLines', 'skippedLinesMore',
            'newMainCategoryNames', 'newSubCategoryNames', 'newBrandNames', 'similarWarnings',
            'createdCount', 'skippedCount', 'createdMainCategories', 'createdSubCategories', 'createdBrands',
        ]);
        $this->step = 'upload';
        $this->resetErrorBag();
    }

    protected function deleteStoredFile(): void
    {
        if ($this->storedPath !== '' && Storage::disk('local')->exists($this->storedPath)) {
            Storage::disk('local')->delete($this->storedPath);
        }

        $this->storedPath = '';
    }

    public function updatedImportFile(): void
    {
        $this->guard();

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:'.ProductImportService::MAX_FILE_SIZE_KB],
        ]);

        $this->deleteStoredFile();
        $this->storedPath = $this->importFile->store('product-imports/'.Auth::user()->shop_id, 'local');
        $this->importFile = null; // the temp upload has served its purpose; only the stored path is kept from here on

        $this->runPreview();
    }

    protected function runPreview(): void
    {
        $this->guard();

        $preview = app(ProductImportService::class)->preview(
            Storage::disk('local')->path($this->storedPath),
            Auth::user()->shop_id,
        );

        $this->applyPreview($preview);
        $this->step = $preview['valid'] ? 'preview' : 'upload';
    }

    protected function applyPreview(array $preview): void
    {
        $this->fileErrors = $preview['fileErrors'];
        $this->fileErrorsMore = $preview['fileErrorsMore'];
        $this->rowErrors = $preview['rowErrors'];
        $this->rowErrorsMore = $preview['rowErrorsMore'];
        $this->toCreateCount = $preview['toCreateCount'];
        $this->toSkipCount = $preview['toSkipCount'];
        $this->skippedLines = $preview['skippedLines'];
        $this->skippedLinesMore = $preview['skippedLinesMore'];
        $this->newMainCategoryNames = $preview['newMainCategoryNames'];
        $this->newSubCategoryNames = $preview['newSubCategoryNames'];
        $this->newBrandNames = $preview['newBrandNames'];
        $this->similarWarnings = $preview['similarWarnings'];
    }

    public function confirmImport(): void
    {
        $this->guard();

        if ($this->processing) {
            return;
        }

        if ($this->storedPath === '' || ! Storage::disk('local')->exists($this->storedPath)) {
            $this->toastError('Your uploaded file has expired or is missing — please upload it again.');
            $this->deleteStoredFile();
            $this->step = 'upload';

            return;
        }

        $this->processing = true;

        $outcome = app(ProductImportService::class)->commit(
            Storage::disk('local')->path($this->storedPath),
            Auth::user()->shop_id,
        );

        $this->deleteStoredFile();

        if (! $outcome['success']) {
            $this->applyPreview($outcome);
            $this->step = 'upload';
            $this->processing = false;
            $this->toastError('The file no longer validates — please review the problems below and upload again.');

            return;
        }

        $this->createdCount = $outcome['createdProducts'];
        $this->skippedCount = $outcome['skippedProducts'];
        $this->createdMainCategories = $outcome['createdMainCategories'];
        $this->createdSubCategories = $outcome['createdSubCategories'];
        $this->createdBrands = $outcome['createdBrands'];

        $this->step = 'done';
        $this->processing = false;
        $this->dispatch('product-import-finished');
        $this->toastSuccess("Import complete — {$this->createdCount} product(s) added.");
    }

    public function cancelImport(): void
    {
        $this->resetState();
        $this->dispatch('close-modal', name: 'product-import');
    }

    public function downloadTemplate(): StreamedResponse
    {
        $this->guard();

        return app(ProductImportService::class)->templateResponse();
    }
}; ?>

<div>
    <x-ui.modal name="product-import" max-width="xl">
        <div class="p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-900">Import Products</h2>
                <button type="button" wire:click="cancelImport" class="text-slate-400 hover:text-slate-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            @if ($step === 'upload')
                <div class="mt-5 space-y-5">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        <p class="mb-2 font-medium text-slate-900">Required columns: Name, Main Category, Selling Price.</p>
                        <p class="mb-2">Optional: Cost Price, Stock Qty. Sub-Category is required only when Main Category is Accessory. Brand is required only when Main Category is Mobile Phone (Model and IMEI/Serial are optional and only apply to Mobile Phone).</p>
                        <p>A Main Category, Sub-Category, or Brand name that doesn't exist yet is created automatically. Images cannot be imported — add photos afterwards from the Products screen.</p>
                    </div>

                    <x-ui.button type="button" variant="secondary" size="sm" wire:click="downloadTemplate" wire:loading.attr="disabled" wire:target="downloadTemplate">
                        Download Template
                    </x-ui.button>

                    <x-ui.field label="Excel / CSV File" name="importFile" for="importFile" help="xlsx, xls, or csv — up to 2MB, up to 500 rows">
                        <input
                            type="file"
                            wire:model="importFile"
                            id="importFile"
                            accept=".xlsx,.xls,.csv"
                            class="block w-full text-sm text-slate-600 file:mr-4 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
                        >
                    </x-ui.field>

                    <div wire:loading wire:target="importFile" class="text-sm text-slate-500">
                        Uploading and checking your file…
                    </div>

                    @if (! empty($fileErrors) || ! empty($rowErrors))
                        <div class="rounded-lg border border-red-200 bg-red-50 p-4">
                            <p class="mb-2 text-sm font-semibold text-red-800">This file can't be imported yet — nothing was saved. Fix these and re-upload:</p>
                            <ul class="max-h-64 space-y-1 overflow-y-auto text-sm text-red-700">
                                @foreach ($fileErrors as $error)
                                    <li>• {{ $error }}</li>
                                @endforeach
                                @if ($fileErrorsMore > 0)
                                    <li class="italic">…and {{ $fileErrorsMore }} more.</li>
                                @endif
                                @foreach ($rowErrors as $error)
                                    <li>• {{ $error }}</li>
                                @endforeach
                                @if ($rowErrorsMore > 0)
                                    <li class="italic">…and {{ $rowErrorsMore }} more.</li>
                                @endif
                            </ul>
                        </div>
                    @endif
                </div>

                <div class="mt-6 flex justify-end">
                    <x-ui.button type="button" variant="secondary" wire:click="cancelImport">Cancel</x-ui.button>
                </div>
            @elseif ($step === 'preview')
                <div class="mt-5 space-y-5">
                    <div class="grid grid-cols-2 gap-4">
                        <x-ui.stat label="Products to Add" :value="number_format($toCreateCount)" />
                        <x-ui.stat label="Skipped (Already Exist)" :value="number_format($toSkipCount)" />
                    </div>

                    @if (! empty($newMainCategoryNames) || ! empty($newSubCategoryNames) || ! empty($newBrandNames))
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                            <p class="mb-2 text-sm font-semibold text-amber-900">These are new and will be created — check carefully for typos before confirming:</p>
                            <div class="space-y-2 text-sm text-amber-800">
                                @if (! empty($newMainCategoryNames))
                                    <p><span class="font-semibold">New Main Categories:</span> {{ implode(', ', $newMainCategoryNames) }}</p>
                                @endif
                                @if (! empty($newSubCategoryNames))
                                    <p><span class="font-semibold">New Sub-Categories:</span> {{ implode(', ', $newSubCategoryNames) }}</p>
                                @endif
                                @if (! empty($newBrandNames))
                                    <p><span class="font-semibold">New Brands:</span> {{ implode(', ', $newBrandNames) }}</p>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if (! empty($similarWarnings))
                        <div class="rounded-lg border border-orange-200 bg-orange-50 p-4">
                            <p class="mb-2 text-sm font-semibold text-orange-900">Possible typos:</p>
                            <ul class="space-y-1 text-sm text-orange-800">
                                @foreach ($similarWarnings as $warning)
                                    <li>• {{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (! empty($skippedLines))
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="mb-2 text-sm font-semibold text-slate-700">Rows that will be skipped:</p>
                            <ul class="max-h-40 space-y-1 overflow-y-auto text-sm text-slate-600">
                                @foreach ($skippedLines as $line)
                                    <li>• {{ $line }}</li>
                                @endforeach
                                @if ($skippedLinesMore > 0)
                                    <li class="italic">…and {{ $skippedLinesMore }} more.</li>
                                @endif
                            </ul>
                        </div>
                    @endif
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" wire:click="cancelImport" wire:loading.attr="disabled" wire:target="confirmImport">
                        Cancel
                    </x-ui.button>
                    <x-ui.button
                        type="button"
                        wire:click="confirmImport"
                        wire:loading.attr="disabled"
                        wire:target="confirmImport"
                        :disabled="$processing"
                    >
                        Confirm Import
                    </x-ui.button>
                </div>
            @elseif ($step === 'done')
                <div class="mt-5 space-y-4">
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                        <p class="font-semibold">Import complete.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <x-ui.stat label="Products Created" :value="number_format($createdCount)" />
                        <x-ui.stat label="Skipped (Already Existed)" :value="number_format($skippedCount)" />
                        <x-ui.stat label="Main Categories Created" :value="number_format($createdMainCategories)" />
                        <x-ui.stat label="Sub-Categories Created" :value="number_format($createdSubCategories)" />
                        <x-ui.stat label="Brands Created" :value="number_format($createdBrands)" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <x-ui.button type="button" wire:click="cancelImport">Done</x-ui.button>
                </div>
            @endif
        </div>
    </x-ui.modal>
</div>
