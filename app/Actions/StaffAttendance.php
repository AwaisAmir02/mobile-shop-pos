<?php

namespace App\Actions;

use App\Enums\CheckInSource;
use App\Enums\SalaryType;
use App\Enums\ServiceTradeTool;
use App\Models\BiometricDevice;
use App\Models\Employee;
use App\Models\Payslip;
use App\Models\Shop;
use App\Models\StaffPunch;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Staff attendance (Services group), on the same mechanisms as member
 * check-in: a fingerprint punch from the device (the PIN is the staff
 * member's attendance_pin, from the same number range as members' member_no),
 * a QR badge scanned at the desk, or a tap on the Attendance screen.
 *
 * A day is present when it has any punch; its first punch is the time in and
 * its last the time out. Payroll: a daily-wage payslip starts with the days
 * present in its month (prefillDays); the owner can still change them.
 */
class StaffAttendance
{
    public static function punch(Employee $employee, CheckInSource $source, ?CarbonInterface $at = null, ?BiometricDevice $device = null, ?int $userId = null): ?StaffPunch
    {
        $punch = new StaffPunch([
            'employee_id' => $employee->id,
            'punched_at' => ($at ?? now())->copy()->utc(),
            'source' => $source,
            'biometric_device_id' => $device?->id,
            'device_pin' => $device !== null ? $employee->attendance_pin : null,
            'user_id' => $userId,
        ]);
        $punch->shop_id = $employee->shop_id;
        $punch->branch_id = $device?->branch_id ?? $employee->branch_id;

        try {
            $punch->save();
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $punch;
    }

    /**
     * A device punch for a staff PIN: null when the PIN isn't a staff member's
     * (so it is a member's), false for a punch already saved, true when saved.
     */
    public static function fromDevice(BiometricDevice $device, int $pin, CarbonInterface $at): ?bool
    {
        $employee = Employee::withoutGlobalScopes()->where('shop_id', $device->shop_id)->where('attendance_pin', $pin)->first();

        return $employee === null ? null : self::punch($employee, CheckInSource::Device, $at, $device) !== null;
    }

    /**
     * One row per staff member for a day: time in, time out and hours.
     *
     * @return Collection<int, array{employee: Employee, in: ?CarbonInterface, out: ?CarbonInterface, minutes: int, punches: int, sources: list<string>}>
     */
    public static function day(int $shopId, string $date): Collection
    {
        $start = CarbonImmutable::parse($date, 'Asia/Karachi')->startOfDay()->utc();
        $punches = StaffPunch::withoutGlobalScopes()->where('shop_id', $shopId)->whereBetween('punched_at', [$start, $start->addDay()->subSecond()])->orderBy('punched_at')->get()->groupBy('employee_id');

        return Employee::withoutGlobalScopes()->where('shop_id', $shopId)->where('is_active', true)->orderBy('name')->get()->map(function (Employee $employee) use ($punches) {
            $rows = $punches->get($employee->id, collect());
            $in = $rows->first()?->punched_at?->copy()->timezone('Asia/Karachi');
            $out = $rows->count() > 1 ? $rows->last()->punched_at->copy()->timezone('Asia/Karachi') : null;

            return [
                'employee' => $employee,
                'in' => $in,
                'out' => $out,
                'minutes' => $in && $out ? (int) $in->diffInMinutes($out) : 0,
                'punches' => $rows->count(),
                'sources' => $rows->map(fn (StaffPunch $p) => $p->source->label())->unique()->values()->all(),
            ];
        });
    }

    /** Days with at least one punch, in the shop's own calendar, between two dates. */
    public static function daysPresent(Employee $employee, string $from, string $to): int
    {
        return StaffPunch::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereBetween('punched_at', [CarbonImmutable::parse($from, 'Asia/Karachi')->startOfDay()->utc(), CarbonImmutable::parse($to, 'Asia/Karachi')->endOfDay()->utc()])
            ->get(['punched_at'])
            ->map(fn (StaffPunch $p) => $p->punched_at->copy()->timezone('Asia/Karachi')->toDateString())
            ->unique()
            ->count();
    }

    /**
     * Called by Payroll::generate: a daily-wage payslip, in a shop that records
     * attendance, starts with the days present that month.
     */
    public static function prefillDays(Payslip $payslip): void
    {
        $shop = Shop::find($payslip->shop_id);
        $employee = Employee::withoutGlobalScopes()->find($payslip->employee_id);

        if ($shop === null || $employee === null || ! $shop->usesTool(ServiceTradeTool::StaffAttendance) || $payslip->salary_type !== SalaryType::Daily) {
            return;
        }

        $month = $payslip->period->copy();
        $payslip->days = self::daysPresent($employee, $month->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString());
    }
}
