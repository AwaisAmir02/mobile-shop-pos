<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A booking on a staff member's day, or a walk-in in the queue (walk_in, with
 * a queue number for the day). Billed through the register, which puts the
 * sale on it (App\Actions\Appointments).
 */
class Appointment extends Model
{
    use BelongsToShop, BelongsToBranch;

    protected $fillable = ['shop_id', 'branch_id', 'customer_id', 'customer_name', 'phone', 'employee_id', 'walk_in', 'appointment_date', 'starts_at', 'duration_minutes', 'queue_number', 'status', 'notes', 'sale_id', 'reminded_at', 'user_id'];

    protected $attributes = ['status' => 'booked', 'walk_in' => false, 'duration_minutes' => 30];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'walk_in' => 'boolean',
            'appointment_date' => 'date',
            'starts_at' => 'datetime',
            'duration_minutes' => 'integer',
            'queue_number' => 'integer',
            'reminded_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(AppointmentService::class)->orderBy('id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withoutGlobalScopes();
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(CustomerDeposit::class);
    }

    public function number(): string
    {
        return 'AP-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function whoName(): string
    {
        return $this->customer?->name ?? $this->customer_name ?? 'Walk-in';
    }

    public function whoPhone(): ?string
    {
        return $this->customer?->phone ?? $this->phone;
    }

    public function endsAt(): ?CarbonInterface
    {
        return $this->starts_at?->copy()->addMinutes($this->duration_minutes);
    }

    public function servicesLabel(): string
    {
        return $this->services->pluck('name')->implode(', ');
    }
}
