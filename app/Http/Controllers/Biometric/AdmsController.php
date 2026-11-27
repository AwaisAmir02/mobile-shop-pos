<?php

namespace App\Http\Controllers\Biometric;

use App\Actions\BiometricDevices;
use App\Actions\CheckIns;
use App\Biometric\Adms;
use App\Enums\ServiceTradeTool;
use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where fingerprint devices push (ZKTeco ADMS, see App\Biometric\Adms). The
 * paths are the device's, not ours: /iclock/cdata, /iclock/getrequest,
 * /iclock/devicecmd. No session, no CSRF (routes/iclock.php).
 *
 * The protocol sends nothing but the serial number, so a device is only
 * answered when a shop registered that serial, the device is switched on, and
 * the shop's trade uses fingerprint check-in. Everything else gets a plain 404,
 * the same for an unknown serial as for a known one turned off.
 */
class AdmsController extends Controller
{
    public function cdata(Request $request): Response
    {
        $device = $this->device($request);

        if ($request->isMethod('GET')) {
            $device->forceFill(array_filter([
                'push_version' => mb_substr((string) $request->query('pushver', ''), 0, 20) ?: null,
                'firmware' => mb_substr((string) $request->query('FWVersion', ''), 0, 60) ?: null,
            ]))->save();

            return $this->text(Adms::options($device->serial_number, $device->att_stamp, $device->op_stamp));
        }

        $body = (string) $request->getContent();
        $stamp = mb_substr((string) $request->query('Stamp', ''), 0, 30);

        return match (strtoupper((string) $request->query('table', ''))) {
            'ATTLOG' => $this->attendance($device, $body, $stamp),
            'OPERLOG' => $this->operations($device, $body, $stamp),
            default => $this->text("OK\n"),
        };
    }

    public function getrequest(Request $request): Response
    {
        $device = $this->device($request);

        return $this->text(Adms::commands(BiometricDevices::collect($device)));
    }

    public function devicecmd(Request $request): Response
    {
        $device = $this->device($request);
        BiometricDevices::answered($device, Adms::answers((string) $request->getContent()));

        return $this->text("OK\n");
    }

    protected function attendance(BiometricDevice $device, string $body, string $stamp): Response
    {
        $saved = 0;

        foreach (Adms::punches($body) as $punch) {
            // A staff member's PIN is attendance; anyone else's is a member check-in.
            $staff = \App\Actions\StaffAttendance::fromDevice($device, $punch['pin'], $punch['at']);
            $saved += ($staff ?? (CheckIns::fromDevice($device, $punch['pin'], $punch['at']) !== null)) ? 1 : 0;
        }

        if ($stamp !== '') {
            $device->forceFill(['att_stamp' => $stamp])->save();
        }

        return $this->text('OK: '.$saved."\n");
    }

    protected function operations(BiometricDevice $device, string $body, string $stamp): Response
    {
        $records = Adms::records($body);
        BiometricDevices::keepTemplates($device, $records);

        if ($stamp !== '') {
            $device->forceFill(['op_stamp' => $stamp])->save();
        }

        return $this->text('OK: '.count($records)."\n");
    }

    protected function device(Request $request): BiometricDevice
    {
        $serial = strtoupper(trim((string) $request->query('SN', '')));
        $device = $serial === '' ? null : BiometricDevice::withoutGlobalScopes()->where('serial_number', $serial)->where('is_active', true)->first();
        $shop = $device ? Shop::find($device->shop_id) : null;

        abort_unless($device !== null && $shop !== null && $shop->usesTool(ServiceTradeTool::Biometric), 404);

        $device->forceFill(['last_seen_at' => now(), 'last_ip' => mb_substr((string) $request->ip(), 0, 45)])->save();

        return $device;
    }

    protected function text(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
