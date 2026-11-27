<?php

namespace App\Actions;

use App\Enums\CheckInSource;
use App\Models\BiometricDevice;
use App\Models\CheckIn;
use App\Models\Customer;
use App\Support\CustomerDuesStatement;
use Carbon\CarbonInterface;
use App\Models\Employee;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Members arriving (gyms, trainers): at the desk, by fingerprint through a
 * device (BiometricDevices), or by the QR on their member card (scan()).
 * Staff badges go through scan() too, into StaffAttendance. Each check-in copies the member's standing
 * (Memberships::standing) and what they owe (the dues statement total, the
 * one "what they owe" figure) at that moment, so the desk sees an expired
 * membership or unpaid fees as the member walks in. A check-in is a record,
 * never a gate: the device lets people in by itself.
 */
class CheckIns
{
    public const MEMBER_PREFIX = 'HOKM:';

    public const STAFF_PREFIX = 'HOKS:';

    public static function atDesk(Customer $customer, ?int $userId): CheckIn
    {
        return self::record($customer, now(), CheckInSource::Desk, null, null, $userId);
    }

    /**
     * A punch from a device, matched to the member by PIN (customers.member_no).
     * The same punch sent twice (devices resend after a lost answer) is saved once.
     */
    public static function fromDevice(BiometricDevice $device, int $pin, CarbonInterface $at): ?CheckIn
    {
        $customer = Customer::withoutGlobalScopes()->where('shop_id', $device->shop_id)->where('member_no', $pin)->first();

        try {
            return self::record($customer, $at, CheckInSource::Device, $device, $pin, null);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** What a member's card QR holds; the code is made the first time it's asked for. */
    public static function memberCode(Customer $customer): string
    {
        return self::MEMBER_PREFIX.self::token($customer, 'checkin_token');
    }

    /** What a staff badge QR holds. */
    public static function staffCode(Employee $employee): string
    {
        return self::STAFF_PREFIX.self::token($employee, 'badge_token');
    }

    /**
     * A QR scanned at the desk (a scanner types it in): a member checks in, a
     * staff member's badge clocks them in or out. Codes belong to one shop.
     *
     * @return array{kind: string, name: string, checkIn?: CheckIn}
     */
    public static function scan(int $shopId, string $code, ?int $userId): array
    {
        $code = trim($code);

        if (str_starts_with($code, self::MEMBER_PREFIX)) {
            $customer = Customer::withoutGlobalScopes()->where('shop_id', $shopId)->where('checkin_token', substr($code, strlen(self::MEMBER_PREFIX)))->first();

            if ($customer !== null) {
                return ['kind' => 'member', 'name' => $customer->name, 'checkIn' => self::record($customer, now(), CheckInSource::Qr, null, null, $userId)];
            }
        }

        if (str_starts_with($code, self::STAFF_PREFIX)) {
            $employee = Employee::withoutGlobalScopes()->where('shop_id', $shopId)->where('is_active', true)->where('badge_token', substr($code, strlen(self::STAFF_PREFIX)))->first();

            if ($employee !== null) {
                StaffAttendance::punch($employee, CheckInSource::Qr, now(), null, $userId);

                return ['kind' => 'staff', 'name' => $employee->name];
            }
        }

        throw ValidationException::withMessages(['scan' => 'That card isn\'t one of this shop\'s member cards or staff badges.']);
    }

    protected static function token(Customer|Employee $model, string $column): string
    {
        if ($model->{$column} === null) {
            do {
                $token = Str::random(24);
            } while ($model::withoutGlobalScopes()->where($column, $token)->exists());

            $model->forceFill([$column => $token])->save();
        }

        return $model->{$column};
    }

    /** @return array{standing: \App\Enums\MemberStanding, membership: ?\App\Models\Membership, dues: float} */
    public static function lookup(Customer $customer, string $date): array
    {
        return Memberships::standing($customer, $date) + ['dues' => round(max(0.0, CustomerDuesStatement::for($customer)->total()), 2)];
    }

    protected static function record(?Customer $customer, CarbonInterface $at, CheckInSource $source, ?BiometricDevice $device, ?int $pin, ?int $userId): CheckIn
    {
        $date = $at->copy()->timezone('Asia/Karachi')->toDateString();
        $standing = $customer !== null ? self::lookup($customer, $date) : ['standing' => \App\Enums\MemberStanding::None, 'membership' => null, 'dues' => 0.0];

        $checkIn = new CheckIn([
            'customer_id' => $customer?->id,
            'membership_id' => $standing['membership']?->id,
            'checked_in_at' => $at->copy()->utc(),
            'source' => $source,
            'biometric_device_id' => $device?->id,
            'device_pin' => $pin,
            'standing' => $standing['standing'],
            'dues' => $standing['dues'],
            'user_id' => $userId,
        ]);
        $checkIn->shop_id = $device?->shop_id ?? $customer->shop_id;
        $checkIn->branch_id = $device?->branch_id;
        $checkIn->save();

        return $checkIn;
    }
}
