<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

trait HasImage
{
    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
