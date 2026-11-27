<?php

namespace Tests\Feature;

use App\Actions\Broadcasts;
use App\Enums\BroadcastDelivery;
use App\Models\BroadcastRecipient;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\ServiceTradeFixtures;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

/** One message to many customers, through the shop's WhatsApp where it may, by hand elsewhere. */
class BroadcastsTest extends TestCase
{
    use RefreshDatabase, ServiceTradeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openServiceShop('gym');
        Storage::fake('local');
    }

    public function test_audiences_pick_members_by_standing(): void
    {
        $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], paid: 5000);
        $lapsed = Customer::create(['name' => 'Lapsed', 'phone' => '03111111111']);
        $this->travelTo(now()->subDays(40));
        $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], customer: $lapsed, paid: 5000);
        $this->travelBack();
        \Carbon\Carbon::setTestNow('2026-11-10 08:00:00');
        Customer::create(['name' => 'No phone']);

        $this->assertSame(['Hina'], Broadcasts::recipients($this->shop->id, 'active_members')->pluck('name')->all());
        $this->assertSame(['Lapsed'], Broadcasts::recipients($this->shop->id, 'expired_members')->pluck('name')->all());
        $this->assertSame(['Hina', 'Lapsed'], Broadcasts::recipients($this->shop->id, 'all_customers')->pluck('name')->all());
    }

    public function test_without_whatsapp_everyone_is_sent_by_hand_with_a_link(): void
    {
        $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], paid: 5000);
        $broadcast = Broadcasts::send($this->shop->id, 'active_members', 'Diet plan for this week', UploadedFile::fake()->create('diet.pdf', 20, 'application/pdf'), [], $this->owner->id);

        $recipient = $broadcast->recipients->first();
        $this->assertSame(BroadcastDelivery::ByHand, $recipient->delivery);
        Storage::disk('local')->assertExists($broadcast->attachment_path);

        $url = Broadcasts::openByHand($recipient, $broadcast);
        $this->assertSame('https://wa.me/923001234567?text='.rawurlencode('Diet plan for this week'), $url);
        $this->assertSame(BroadcastDelivery::OpenedByHand, $recipient->fresh()->delivery);
    }

    public function test_the_screen_sends_and_lists(): void
    {
        $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], paid: 5000);

        Volt::test('broadcasts.index')->set('audience', 'active_members')->set('body', 'Gym closed Friday')->call('send')->assertHasNoErrors()
            ->assertSee('data-send-by-hand', false);
        $this->assertSame(1, BroadcastRecipient::count());
    }
}
