<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/** A typed earning ("Bonus") or deduction. Its label and effect are kept as they were when entered. */
class PayslipLine extends Model
{
    use BelongsToShop;

    public const EARNING = 'earning';

    public const DEDUCTION = 'deduction';

    public $timestamps = false;

    protected $fillable = ['shop_id', 'payslip_id', 'kind', 'label', 'amount', 'deduction_type_id', 'reduces_cost'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reduces_cost' => 'boolean',
        ];
    }
}
