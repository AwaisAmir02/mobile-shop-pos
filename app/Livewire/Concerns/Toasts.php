<?php

namespace App\Livewire\Concerns;

trait Toasts
{
    protected function toastSuccess(string $message): void
    {
        $this->dispatch('toast', message: $message, type: 'success');
    }

    protected function toastError(string $message): void
    {
        $this->dispatch('toast', message: $message, type: 'error');
    }
}
