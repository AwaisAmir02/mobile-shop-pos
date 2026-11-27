<?php

namespace Tests\Feature;

use App\Actions\BiometricDevices;
use App\Actions\CheckIns;
use App\Actions\Memberships;
use App\Biometric\Adms;
use App\Enums\BiometricCommandStatus;
use App\Enums\CheckInSource;
use App\Enums\MemberStanding;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;
use App\Models\BiometricTemplate;
use App\Models\CheckIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ServiceTradeFixtures;
use Tests\TestCase;

/** Desk check-ins and fingerprint devices on ZKTeco's ADMS push protocol. */
class BiometricCheckInsTest extends TestCase
{
    use RefreshDatabase, ServiceTradeFixtures;

    protected BiometricDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openServiceShop('gym');
        $this->device = BiometricDevices::register($this->shop->id, ['name' => 'Front door', 'serial_number' => 'cqz7231560051']);
    }

    protected function adms(string $method, string $path, string $query, string $body = ''): \Illuminate\Testing\TestResponse
    {
        return $this->call($method, '/iclock/'.$path.'?'.$query, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    public function test_the_handshake_answers_only_a_registered_serial(): void
    {
        $this->adms('GET', 'cdata', 'SN=CQZ7231560051&options=all&pushver=2.4.1')
            ->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee("GET OPTION FROM: CQZ7231560051\nATTLOGStamp=None", false)
            ->assertSee('Realtime=1', false);
        $this->assertSame('2.4.1', $this->device->fresh()->push_version);
        $this->assertNotNull($this->device->fresh()->last_seen_at);

        $this->adms('GET', 'cdata', 'SN=UNKNOWN1&options=all')->assertNotFound();
        $this->device->update(['is_active' => false]);
        $this->adms('GET', 'getrequest', 'SN=CQZ7231560051')->assertNotFound();
    }

    public function test_punches_become_check_ins_once_with_the_members_standing(): void
    {
        $sale = $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], paid: 4000);
        $pin = Memberships::assignMemberNo($this->client);
        $body = "{$pin}\t2026-11-10 07:15:02\t0\t1\t0\t0\n999\t2026-11-10 07:20:00\t0\t1\t0\t0\n";

        $this->adms('POST', 'cdata', 'SN=CQZ7231560051&table=ATTLOG&Stamp=9999', $body)->assertOk()->assertSee('OK: 2');
        $this->adms('POST', 'cdata', 'SN=CQZ7231560051&table=ATTLOG&Stamp=9999', $body)->assertOk()->assertSee('OK: 0');

        $member = CheckIn::where('customer_id', $this->client->id)->firstOrFail();
        $this->assertSame(CheckInSource::Device, $member->source);
        $this->assertSame(MemberStanding::Active, $member->standing);
        $this->assertSame('1000.00', (string) $member->dues);
        $this->assertSame('2026-11-10 02:15:02', $member->checked_in_at->toDateTimeString(), 'Pakistan time on the device, UTC stored');
        $this->assertNull(CheckIn::where('device_pin', 999)->firstOrFail()->customer_id);
        $this->assertSame('9999', $this->device->fresh()->att_stamp);
    }

    public function test_commands_are_collected_once_and_answered(): void
    {
        BiometricDevices::sendMember($this->client);
        $command = BiometricCommand::firstOrFail();
        $this->assertSame("DATA UPDATE USERINFO PIN=1\tName=Hina\tPri=0\tPasswd=\tCard=\tGrp=1", $command->command);

        $this->adms('GET', 'getrequest', 'SN=CQZ7231560051')->assertOk()->assertSee('C:'.$command->id.':DATA UPDATE USERINFO PIN=1', false);
        $this->adms('GET', 'getrequest', 'SN=CQZ7231560051')->assertOk()->assertSeeText('OK');
        $this->adms('POST', 'devicecmd', 'SN=CQZ7231560051', "ID={$command->id}&Return=0&CMD=DATA\n")->assertOk();

        $this->assertSame(BiometricCommandStatus::Done, $command->fresh()->status);
    }

    public function test_fingerprints_are_kept_encrypted_and_expired_members_are_removed_then_restored(): void
    {
        $this->device->update(['remove_expired' => true]);
        $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], paid: 5000);
        BiometricDevices::sendMember($this->client);
        $this->adms('POST', 'cdata', 'SN=CQZ7231560051&table=OPERLOG&Stamp=5', "FP PIN=1\tFID=6\tSize=8\tValid=1\tTMP=QUJDREVGR0g=\nUSER PIN=1\tName=Hina\n")->assertSee('OK: 2');

        $raw = DB::table('biometric_templates')->value('template');
        $this->assertStringNotContainsString('QUJDREVGR0g=', $raw);
        $this->assertSame('QUJDREVGR0g=', BiometricTemplate::firstOrFail()->template);

        $this->travelTo(now()->addDays(40));
        $this->assertSame(['removed' => 1, 'restored' => 0], BiometricDevices::syncStanding($this->device));
        $this->assertSame('DATA DELETE USERINFO PIN=1', BiometricCommand::latest('id')->value('command'));

        $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], paid: 5000);
        $this->assertSame(['removed' => 0, 'restored' => 1], BiometricDevices::syncStanding($this->device));
        $this->assertSame("DATA UPDATE FINGERTMP PIN=1\tFID=6\tSize=8\tValid=1\tTMP=QUJDREVGR0g=", BiometricCommand::latest('id')->value('command'));
    }

    public function test_the_desk_sees_standing_and_dues(): void
    {
        $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], paid: 0);
        $checkIn = CheckIns::atDesk($this->client, $this->owner->id);

        $this->assertSame(MemberStanding::Active, $checkIn->standing);
        $this->assertSame('5000.00', (string) $checkIn->dues);

        \Livewire\Volt\Volt::test('check-ins.index')->call('look', $this->client->id)->assertSee('Owes Rs 5,000.00');
        $this->get(route('check-ins.index'))->assertOk()->assertSee('Hina');
        $this->get(route('biometric-devices.index'))->assertOk()->assertSee('CQZ7231560051');
    }

    public function test_the_protocol_parsers(): void
    {
        $punches = Adms::punches("7\t2026-11-10 07:15:02\t0\t1\t0\ngarbage\n8\t1762740000\t1\t15");
        $this->assertCount(2, $punches);
        $this->assertSame(7, $punches[0]['pin']);
        $this->assertSame([['id' => 12, 'return' => -1002]], Adms::answers("ID=12&Return=-1002&CMD=DATA\n"));
        $this->assertSame("OK\n", Adms::commands([]));
    }
}
