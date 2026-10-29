<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplate extends Model
{
    use BelongsToShop;

    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'shop_id',
        'shop_channel_connection_id',
        'key',
        'language',
        'name',
        'category',
        'meta_template_id',
        'status',
        'rejection_reason',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
