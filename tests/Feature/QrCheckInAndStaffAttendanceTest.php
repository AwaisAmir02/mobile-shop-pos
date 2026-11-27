<?php

namespace Tests\Feature;

use App\Actions\BiometricDevices;
use App\Actions\CheckIns;
use App\Actions\Memberships;
use App\Actions\Payroll;
use App\Actions\StaffAttendance;
use App\Enums\CheckInSource;
use App\Enums\MemberStanding;
use App\Enums\SalaryType;
use App\Models\CheckIn;
use App\Models\Payslip;
use App\Models\StaffPunch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\Concerns\ServiceTradeFixtures;
use Tests\TestCase;

/** QR member cards and staff badges, and staff attendance on the same devices. */
class QrCheckInAndStaffAttendanceTest extends TestCase
{
    use RefreshDatabase, ServiceTradeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openServiceShop('gym', ['payroll_enabled' => true]);
    }

    public function test_a_member_card_qr_checks_in_with_standing_and_dues(): void
    {
        $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], paid: 3000);
        $code = CheckIns::memberCode($this->client);
        $this->assertMatchesRegularExpression('/^HOKM:[A-Za-z0-9]{24}$/', $code);
        $this->assertSame($code, CheckIns::memberCode($this->client->fresh()), 'The code is made once');

        $result = CheckIns::scan($this->shop->id, ' '.$code.' ', $this->owner->id);
        $this->assertSame('member', $result['kind']);
        $this->assertSame(CheckInSource::Qr, $result['checkIn']->source);
        $this->assertSame(MemberStanding::Active, $result['checkIn']->standing);
        $this->assertSame('2000.00', (string) $result['checkIn']->dues);
    }

    public function test_a_staff_badge_clocks_and_unknown_or_other_shop_codes_are_refused(): void
    {
        $badge = CheckIns::staffCode($this->ali);
        $this->assertSame('staff', CheckIns::scan($this->shop->id, $badge, null)['kind']);
        $this->assertSame(1, StaffPunch::where('employee_id', $this->ali->id)->count());
        $this->assertSame(0, CheckIn::count());

        $this->expectException(ValidationException::class);
        CheckIns::scan(\App\Models\Shop::create(['name' => 'Other gym', 'business_category' => 'gym'])->id, $badge, null);
    }

    public function test_members_and_staff_share_one_pin_range_and_the_device_sorts_them(): void
    {
        $device = BiometricDevices::register($this->shop->id, ['name' => 'Door', 'serial_number' => 'GYM1']);
        $this->assertSame(1, Memberships::assignMemberNo($this->client));
        BiometricDevices::sendStaff($this->ali);
        $this->assertSame(2, $this->ali->fresh()->attendance_pin);
        $this->assertSame(3, Memberships::assignMemberNo(\App\Models\Customer::create(['name' => 'Next', 'phone' => '03009998887'])));

        $body = "1\t2026-11-10 07:00:00\t0\t1\t0\n2\t2026-11-10 08:55:00\t0\t1\t0\n2\t2026-11-10 17:05:00\t1\t1\t0\n";
        $this->call('POST', '/iclock/cdata?SN=GYM1&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)->assertOk()->assertSee('OK: 3');

        $this->assertSame(1, CheckIn::count());
        $this->assertSame(2, StaffPunch::where('employee_id', $this->ali->id)->where('source', 'device')->count());
        $day = StaffAttendance::day($this->shop->id, '2026-11-10')->firstWhere('employee.id', $this->ali->id);
        $this->assertSame('8:55 AM', $day['in']->format('g:i A'));
        $this->assertSame('5:05 PM', $day['out']->format('g:i A'));
        $this->assertSame(490, $day['minutes']);
    }

    public function test_a_daily_wage_payslip_starts_with_the_days_present(): void
    {
        $this->sara->update(['salary_type' => SalaryType::Daily, 'rate' => 1500]);

        foreach (['2026-11-03 09:00', '2026-11-03 18:00', '2026-11-04 09:00', '2026-11-05 09:00'] as $time) {
            StaffAttendance::punch($this->sara, CheckInSource::Desk, \Carbon\Carbon::parse($time, 'Asia/Karachi'));
        }

        Payroll::generate($this->shop->id, '2026-11', null, $this->owner->id);
        $slip = Payslip::where('employee_id', $this->sara->id)->firstOrFail();
        $this->assertSame('3.00', (string) $slip->days);
        $this->assertSame('4500.00', (string) $slip->base_pay);
        $this->assertNull(Payslip::where('employee_id', $this->ali->id)->firstOrFail()->days, 'A fixed salary is untouched');
    }

    public function test_shops_without_attendance_keep_daily_payslips_blank(): void
    {
        $retail = \App\Models\Shop::create(['name' => 'K', 'business_category' => 'kiryana', 'payroll_enabled' => true]);
        $this->actingAs(\App\Models\User::factory()->create(['shop_id' => $retail->id, 'is_owner' => true]));
        $worker = \App\Models\Employee::create(['shop_id' => $retail->id, 'name' => 'W', 'salary_type' => SalaryType::Daily, 'rate' => 1000, 'joined_on' => '2026-01-01']);

        Payroll::generate($retail->id, '2026-11', null, null);
        $this->assertNull(Payslip::where('employee_id', $worker->id)->firstOrFail()->days);
    }

    public function test_the_screens_scan_clock_and_print(): void
    {
        $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], paid: 5000);

        Volt::test('check-ins.index')->set('scan', CheckIns::memberCode($this->client))->call('scanCode')->assertSee('Hina');
        $this->assertSame(1, CheckIn::where('source', 'qr')->count());

        Volt::test('staff-attendance.index')->call('clock', $this->ali->id)->assertSee('Ali');
        $this->assertSame(1, StaffPunch::where('source', 'desk')->count());
        $this->get(route('staff-attendance.index'))->assertOk()->assertSee('Clock in/out');

        $html = view('pdf.checkin-card', ['shop' => $this->shop, 'title' => 'Member card', 'name' => 'Hina', 'number' => 1, 'qr' => app(\App\Services\Marketing\QrCodeService::class)->dataUri(CheckIns::memberCode($this->client))])->render();
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringStartsWith('%PDF', app(\App\Services\CheckInCardPdfService::class)->render($this->ali));
    }
}
