<?php

namespace App\Actions;

use App\Biometric\Adms;
use App\Enums\BiometricCommandStatus;
use App\Enums\MemberStanding;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;
use App\Models\BiometricTemplate;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fingerprint devices on ZKTeco's ADMS protocol (gyms). The shop registers a
 * device by its serial number and points the device's "Cloud server" setting
 * at our address; the device then pushes punches, which become check-ins
 * (CheckIns::fromDevice), and collects the commands queued here.
 *
 * A member is known to the device by their member number (PIN). Sending a
 * member adds them as a user; the finger itself is enrolled on the device.
 * With "remove expired members" on, members:sync-devices takes members whose
 * membership ran out off the device (so the door or turnstile stops letting
 * them in) and puts them back, with any fingerprint the device sent us, once
 * they renew.
 */
class BiometricDevices
{
    public static function register(int $shopId, array $fields): BiometricDevice
    {
        $serial = strtoupper(trim((string) ($fields['serial_number'] ?? '')));

        validator(['serial_number' => $serial, 'name' => $fields['name'] ?? ''], [
            'serial_number' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9\-]+$/', Rule::unique('biometric_devices', 'serial_number')],
            'name' => ['required', 'string', 'max:100'],
        ], ['serial_number.unique' => 'That device is already registered (by this or another business).', 'serial_number.regex' => 'Type the serial number exactly as the device shows it (letters and numbers).'])->validate();

        $device = new BiometricDevice(['name' => trim($fields['name']), 'serial_number' => $serial, 'branch_id' => $fields['branch_id'] ?? null, 'remove_expired' => (bool) ($fields['remove_expired'] ?? false)]);
        $device->shop_id = $shopId;
        $device->save();

        return $device;
    }

    /**
     * The next free PIN in a shop. Members (member_no) and staff (attendance_pin)
     * share one range, because a device knows everyone by PIN alone. Call
     * inside a transaction.
     */
    public static function nextPin(int $shopId): int
    {
        $members = (int) Customer::withoutGlobalScopes()->where('shop_id', $shopId)->lockForUpdate()->max('member_no');
        $staff = (int) \App\Models\Employee::withoutGlobalScopes()->where('shop_id', $shopId)->lockForUpdate()->max('attendance_pin');

        return max($members, $staff) + 1;
    }

    /** Gives a staff member their attendance PIN (once) and queues them on every active device. */
    public static function sendStaff(\App\Models\Employee $employee): int
    {
        $pin = DB::transaction(function () use ($employee) {
            $employee = \App\Models\Employee::withoutGlobalScopes()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if ($employee->attendance_pin === null) {
                $employee->forceFill(['attendance_pin' => self::nextPin($employee->shop_id)])->save();
            }

            return (int) $employee->attendance_pin;
        });

        $queued = 0;

        foreach (self::devicesFor($employee->shop_id) as $device) {
            self::queue($device, Adms::userCommand($pin, $employee->name));
            $queued++;
        }

        if ($queued === 0) {
            throw ValidationException::withMessages(['device' => 'No fingerprint device is set up yet. Add one under Fingerprint Devices.']);
        }

        return $queued;
    }

    /** Queues a member to be added (or renamed) on every active device of the shop. */
    public static function sendMember(Customer $customer): int
    {
        $pin = Memberships::assignMemberNo($customer);
        $queued = 0;

        foreach (self::devicesFor($customer->shop_id) as $device) {
            self::queue($device, Adms::userCommand($pin, $customer->name), $customer->id);
            $queued++;
        }

        if ($queued === 0) {
            throw ValidationException::withMessages(['device' => 'No fingerprint device is set up yet. Add one under Fingerprint Devices.']);
        }

        return $queued;
    }

    /**
     * For devices set to remove expired members: takes off members whose
     * membership is no longer running and puts back those running again.
     * Which members a device holds is remembered from what we sent it.
     *
     * @return array{removed: int, restored: int}
     */
    public static function syncStanding(BiometricDevice $device): array
    {
        $removed = 0;
        $restored = 0;
        $today = now('Asia/Karachi')->toDateString();

        $members = Customer::withoutGlobalScopes()->where('shop_id', $device->shop_id)->whereNotNull('member_no')->get();

        foreach ($members as $customer) {
            $standing = Memberships::standing($customer, $today)['standing'];
            $onDevice = self::lastCommandFor($device, (int) $customer->id);
            $isOn = $onDevice !== null && str_starts_with($onDevice->command, 'DATA UPDATE USERINFO');
            $shouldBeOn = $standing === MemberStanding::Active;

            if ($isOn && ! $shouldBeOn && in_array($standing, [MemberStanding::Expired, MemberStanding::Frozen, MemberStanding::UsedUp], true)) {
                self::queue($device, Adms::deleteUserCommand((int) $customer->member_no), $customer->id);
                $removed++;
            } elseif (! $isOn && $onDevice !== null && $shouldBeOn) {
                self::queue($device, Adms::userCommand((int) $customer->member_no, $customer->name), $customer->id);

                foreach (BiometricTemplate::withoutGlobalScopes()->where('shop_id', $device->shop_id)->where('pin', $customer->member_no)->get() as $template) {
                    self::queue($device, Adms::fingerCommand((int) $template->pin, (int) $template->finger, (int) $template->size, $template->template), $customer->id);
                }

                $restored++;
            }
        }

        return ['removed' => $removed, 'restored' => $restored];
    }

    public static function queue(BiometricDevice $device, string $command, ?int $customerId = null): BiometricCommand
    {
        return BiometricCommand::create(['shop_id' => $device->shop_id, 'biometric_device_id' => $device->id, 'customer_id' => $customerId, 'command' => $command]);
    }

    /** The commands the device collects now: oldest first, a few at a time, marked sent. */
    public static function collect(BiometricDevice $device): \Illuminate\Support\Collection
    {
        return DB::transaction(function () use ($device) {
            $commands = BiometricCommand::withoutGlobalScopes()->where('biometric_device_id', $device->id)->where('status', BiometricCommandStatus::Queued->value)->orderBy('id')->limit(10)->lockForUpdate()->get();

            foreach ($commands as $command) {
                $command->update(['status' => BiometricCommandStatus::Sent, 'sent_at' => now()]);
            }

            return $commands;
        });
    }

    /** @param list<array{id: int, return: int}> $answers */
    public static function answered(BiometricDevice $device, array $answers): void
    {
        foreach ($answers as $answer) {
            BiometricCommand::withoutGlobalScopes()->where('biometric_device_id', $device->id)->whereKey($answer['id'])->update([
                'status' => $answer['return'] === 0 ? BiometricCommandStatus::Done->value : BiometricCommandStatus::Failed->value,
                'return_code' => $answer['return'],
                'answered_at' => now(),
            ]);
        }
    }

    /** Fingerprint templates the device sent up, kept encrypted to restore a removed member. */
    public static function keepTemplates(BiometricDevice $device, array $records): int
    {
        // Biometric data: kept only when the shop has the device take expired members off.
        if (! $device->remove_expired) {
            return 0;
        }

        $kept = 0;

        foreach ($records as $record) {
            $fields = $record['fields'];

            if ($record['type'] !== 'FP' || ! ctype_digit((string) ($fields['PIN'] ?? '')) || ($fields['TMP'] ?? '') === '') {
                continue;
            }

            BiometricTemplate::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $device->shop_id, 'pin' => (int) $fields['PIN'], 'finger' => (int) ($fields['FID'] ?? 0)],
                ['template' => $fields['TMP'], 'size' => (int) ($fields['SIZE'] ?? strlen($fields['TMP']))],
            );
            $kept++;
        }

        return $kept;
    }

    /** @return \Illuminate\Support\Collection<int, BiometricDevice> */
    protected static function devicesFor(int $shopId): \Illuminate\Support\Collection
    {
        return BiometricDevice::withoutGlobalScopes()->where('shop_id', $shopId)->where('is_active', true)->get();
    }

    protected static function lastCommandFor(BiometricDevice $device, int $customerId): ?BiometricCommand
    {
        return BiometricCommand::withoutGlobalScopes()
            ->where('biometric_device_id', $device->id)
            ->where('customer_id', $customerId)
            ->where('status', '!=', BiometricCommandStatus::Failed->value)
            ->where(fn ($q) => $q->where('command', 'like', 'DATA UPDATE USERINFO%')->orWhere('command', 'like', 'DATA DELETE USERINFO%'))
            ->latest('id')
            ->first();
    }
}
