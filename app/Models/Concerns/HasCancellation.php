<?php

namespace App\Models\Concerns;

use App\Enums\RecordStatus;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasCancellation
{
    public static function bootHasCancellation(): void
    {
        static::addGlobalScope(new ExcludesCancelledScope);
    }

    public function revisions(): MorphMany
    {
        return $this->morphMany(ModuleRevision::class, 'revisionable')->latest('created_at');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === RecordStatus::Cancelled;
    }

    /**
     * None of these models have a dedicated show/edit route today — every
     * mutating entry point loads them explicitly via
     * withoutGlobalScope(ExcludesCancelledScope::class)::findOrFail(), so
     * this is only needed if a future screen adds route-model binding.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->withoutGlobalScope(ExcludesCancelledScope::class)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
