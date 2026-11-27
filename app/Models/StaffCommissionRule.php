<?php

namespace App\Models;

use App\Enums\CommissionKind;
use App\Enums\CommissionTarget;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One commission rule (App\Support\StaffEarnings). No employee means everyone without their own rule. */
class StaffCommissionRule extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'employee_id', 'target', 'target_id', 'kind', 'value', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['target' => CommissionTarget::class, 'kind' => CommissionKind::class, 'value' => 'decimal:2', 'is_active' => 'boolean', 'target_id' => 'integer', 'employee_id' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function rateLabel(): string
    {
        return $this->kind === CommissionKind::Percent
            ? rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'%'
            : 'Rs '.number_format((float) $this->value, 2).' each';
    }
}
