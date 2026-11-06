<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedgerRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerEntry extends Model
{
    use BelongsToShop, ImmutableLedgerRow;

    public const UPDATED_AT = null;

    protected $fillable = ['shop_id', 'epoch', 'entry_date', 'source_type', 'source_id', 'description', 'created_at'];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class);
    }
}
