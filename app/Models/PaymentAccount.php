<?php

namespace App\Models;

use App\Enums\PaymentAccountKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** HisabOKitab's own accounts that shops pay their plan into (bank, JazzCash, Easypaisa). */
class PaymentAccount extends Model
{
    protected $fillable = [
        'kind',
        'provider',
        'account_title',
        'account_number',
        'iban',
        'note',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PaymentAccountKind::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** "Meezan Bank" for a bank, "JazzCash" for a wallet. */
    public function heading(): string
    {
        return $this->kind->needsProvider() && $this->provider ? $this->provider : $this->kind->label();
    }
}
