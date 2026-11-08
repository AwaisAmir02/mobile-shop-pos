<?php

namespace App\Support;

use Illuminate\Support\Js;

/**
 * The styled confirmation that replaces wire:confirm (docs/design-system.md).
 *
 * The visible control asks through the Alpine `confirm` store and, on yes,
 * clicks a hidden twin carrying the original wire:click. So the Livewire action,
 * its arguments and every test that calls it stay exactly as they were.
 */
class ConfirmAction
{
    /**
     * The x-on:click expression for the visible control. Its hidden twin must be
     * the next element.
     */
    public static function handler(string $message, ?string $title = null, ?string $confirmLabel = null, bool $danger = false, string $before = ''): string
    {
        $ask = Js::from([
            'title' => $title ?? 'Are you sure?',
            'message' => $message,
            'confirmLabel' => $confirmLabel ?? 'Confirm',
            'danger' => $danger,
        ]);

        return trim($before.' $store.confirm.ask('.$ask.').then((ok) => ok && $el.nextElementSibling.click())');
    }
}
