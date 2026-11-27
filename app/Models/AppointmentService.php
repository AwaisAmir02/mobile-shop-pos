<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One service on a booking, with who will do it (the appointment's staff member unless set). */
class AppointmentService extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'appointment_id', 'service_item_id', 'name', 'employee_id', 'price', 'duration_minutes'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'duration_minutes' => 'integer'];
    }

    public function serviceItem(): BelongsTo
    {
        return $this->belongsTo(ServiceItem::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
