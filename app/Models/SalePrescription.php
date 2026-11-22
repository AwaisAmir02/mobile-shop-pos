<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of the prescription / controlled-drug register. See App\Actions\Prescriptions. */
class SalePrescription extends Model
{
    use BelongsToShop;

    public const PRESCRIPTION = 'prescription';

    public const CONTROLLED = 'controlled';

    protected $fillable = [
        'shop_id',
        'sale_id',
        'product_id',
        'serial_number',
        'product_name',
        'control',
        'quantity',
        'dispensed_on',
        'patient_name',
        'prescriber_name',
        'prescriber_reg_no',
        'prescribed_on',
        'is_emergency',
        'emergency_reason',
        'photo_path',
        'source',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'dispensed_on' => 'date',
            'prescribed_on' => 'date',
            'is_emergency' => 'boolean',
            'serial_number' => 'integer',
        ];
    }

    /** "Prescription only" or "Controlled". */
    public static function controlLabel(?string $control): ?string
    {
        return match ($control) {
            self::PRESCRIPTION => 'Prescription only',
            self::CONTROLLED => 'Controlled',
            default => null,
        };
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withoutGlobalScopes();
    }

    /** Neither a prescriber nor an emergency reason yet: a sale not made at the register. */
    public function detailsMissing(): bool
    {
        return $this->prescriber_name === null && ! $this->is_emergency;
    }
}
