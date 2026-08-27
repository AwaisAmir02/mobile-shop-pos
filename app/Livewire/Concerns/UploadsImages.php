<?php

namespace App\Livewire\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

trait UploadsImages
{
    protected function storeImage(UploadedFile $file, string $folder, ?string $previousPath = null): string
    {
        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }

        return $file->store('shop-'.Auth::user()->shop_id.'/'.$folder, 'public');
    }

    protected function deleteImage(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    public function previewUrl($temporaryFile, ?string $fallbackUrl): ?string
    {
        if ($temporaryFile && $temporaryFile->isPreviewable()) {
            return $temporaryFile->temporaryUrl();
        }

        return $fallbackUrl;
    }
}
