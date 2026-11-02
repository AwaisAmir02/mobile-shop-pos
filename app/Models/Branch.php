<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A location of a shop. Every shop has exactly one Main branch, created
 * with the shop. Branches are archived, never deleted, so their history
 * keeps its label. Catalog, customers, vendors and stock are shared by all
 * branches of a shop; only transactions are recorded per branch.
 */
class Branch extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'name',
        'address',
        'phone',
        'is_main',
        'archived_at',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => BranchContext::current()->forget());
    }

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_main')->orderBy('name');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
