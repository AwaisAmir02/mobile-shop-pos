<?php

namespace App\Models;

use App\Enums\RevisionAction;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ModuleRevision extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $fillable = [
        'shop_id',
        'revisionable_type',
        'revisionable_id',
        'action',
        'user_id',
        'reason',
        'snapshot',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => RevisionAction::class,
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function revisionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
