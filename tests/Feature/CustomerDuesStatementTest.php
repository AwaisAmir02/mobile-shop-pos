<?php

namespace Tests\Feature;

use App\Enums\ChannelProvider;
use App\Enums\ConnectionStatus;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Shop;
use App\Models\ShopChannelConnection;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\CustomerNotifications;
use App\Support\CustomerDuesStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class CustomerDuesStatementTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected function connectWhatsApp(Shop $shop): ShopChannelConnection
    {
        $shop->update(['whatsapp_enabled' => true]);

        return ShopChannelConnection::create([
            'shop_id' => $shop->id,
            'provider' => ChannelProvider::WhatsApp,
            'external_id' => '555000',
            'waba_id' => '900',
            'access_token' => 'tok',
            'status' => ConnectionStatus::Connected,
            'connected_at' => now(),
        ]);
    }

    public function test_statement_matches_the_udhaar_screen_item_for_item(): void
    {
        [, , $bilal, $sana] = DuesBaselineTest::seedDuesFixture($this);

        foreach ([$bilal, $sana] as $customer) {
            $statement = CustomerDuesStatement::for($customer);

            Livewire::test('udhaar.show', ['customer' => $customer])
                ->assertViewHas('balance', $statement->total())
                ->assertViewHas('dues', function ($dues) use ($statement) {
                    $expected = $dues->map(fn ($d) => [$d['source'], $d['id'], (float) $d['amountDue']])->sort()->values()->all();
                    $actual = $statement->items()->map(fn ($d) => [$d['source'], $d['id'], (float) $d['amountDue']])->sort()->values()->all();

                    return $expected === $actual;
                });
        }

        $this->assertSame(11350.0, CustomerDuesStatement::for($bilal)->total());
        $this->assertSame(-200.0, CustomerDuesStatement::for($sana)->total());
    }

    public function test_reminder_names_every_item_and_its_source_in_english_and_roman_urdu(): void
    {
        [, , $bilal] = DuesBaselineTest::seedDuesFixture($this);
        $notifications = app(CustomerNotifications::class);

        $english = $notifications->paymentReminderPreview($bilal);
        $this->assertSame(11350.0, $english['total']);
        $this->assertCount(9, $english['lines']);
        $this->assertStringContainsString('You bought iPhone 13 (', $english['text']);
        $this->assertStringContainsString('Rs 5,000 remaining', $english['text']);
        $this->assertStringContainsString('Mobile repair (Screen replacement)', $english['text']);
        $this->assertStringContainsString('Rs 1,200 remaining', $english['text']);
        $this->assertStringContainsString('Udhaar (cash loan) — Rs 2,000 remaining', $english['text']);
        $this->assertStringContainsString('Electricity bill', $english['text']);
        $this->assertStringContainsString('Screen Guard service', $english['text']);
        $this->assertStringContainsString('Total due: Rs 11,350', $english['text']);
        $this->assertStringNotContainsString('Cable', $english['text']);
        $this->assertStringNotContainsString('Paid repair', $english['text']);
        $this->assertSame(1, substr_count($english['text'], 'Rs 5,000 remaining'));
        $this->assertSame(1, substr_count($english['text'], 'JazzCash wallet load'));

        $contact = new WhatsAppContact(['language' => 'ur']);
        $urdu = $notifications->paymentReminderPreview($bilal, $contact);
        $this->assertStringContainsString('Aapne iPhone 13 liya tha (', $urdu['text']);
        $this->assertStringContainsString('Udhaar mein Rs 2,000 baaki', $urdu['text']);
        $this->assertStringContainsString('Kul baaki: Rs 11,350', $urdu['text']);
    }

    public function test_an_advance_is_shown_as_deducted_and_lowers_the_total(): void
    {
        [, , , $sana] = DuesBaselineTest::seedDuesFixture($this);

        $preview = app(CustomerNotifications::class)->paymentReminderPreview($sana);

        $this->assertSame(-200.0, $preview['total']);
        $this->assertStringContainsString('Advance you have with us — Rs 500 (deducted)', $preview['text']);
    }

    public function test_reminder_is_sent_as_chat_inside_24h_and_as_template_outside_with_items_in_one_line(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $bilal] = DuesBaselineTest::seedDuesFixture($this);
        $connection = $this->connectWhatsApp($shop);
        $this->approveTemplates($connection);

        $notification = app(CustomerNotifications::class)->paymentReminder($bilal, $owner->id);

        $this->assertTrue($notification->wasSent());
        $this->assertSame('template', $notification->delivery_mode);
        $template = collect($this->sentBodies())->last()['template'];
        $this->assertSame('hok_payment_reminder_v1', $template['name']);
        $params = array_column($template['components'][0]['parameters'], 'text');
        $this->assertSame(['Bilal Khan', 'Dues Shop'], array_slice($params, 0, 2));
        $this->assertStringNotContainsString("\n", $params[2]);
        $this->assertStringContainsString('You bought iPhone 13', $params[2]);
        $this->assertSame('11,350', $params[3]);
        $this->assertSame('923001112223', collect($this->sentBodies())->last()['to']);

        $sana = Customer::where('name', 'Sana')->first();
        WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => '923214445556', 'customer_id' => $sana->id, 'last_inbound_at' => now()]);
        $this->expectExceptionMessage('nothing outstanding');
        app(CustomerNotifications::class)->paymentReminder($sana, $owner->id);
    }

    public function test_reminders_are_throttled_per_customer(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $bilal] = DuesBaselineTest::seedDuesFixture($this);
        $connection = $this->connectWhatsApp($shop);
        WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => '923001112223', 'customer_id' => $bilal->id, 'last_inbound_at' => now()]);

        $first = app(CustomerNotifications::class)->paymentReminder($bilal, $owner->id);
        $this->assertSame('session', $first->delivery_mode);
        $this->assertStringContainsString('You bought iPhone 13', collect($this->sentBodies())->last()['text']['body']);

        try {
            app(CustomerNotifications::class)->paymentReminder($bilal, $owner->id);
            $this->fail('Expected the second reminder to be blocked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already sent', $e->getMessage());
        }

        $this->travel(CustomerNotifications::REMINDER_COOLDOWN_HOURS + 1)->hours();
        WhatsAppContact::where('customer_id', $bilal->id)->update(['last_inbound_at' => now()]);
        $this->assertTrue(app(CustomerNotifications::class)->paymentReminder($bilal, $owner->id)->wasSent());
        $this->assertSame(2, CustomerNotification::where('customer_id', $bilal->id)->count());
    }

    public function test_without_an_approved_template_an_out_of_window_reminder_is_logged_as_not_sent(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $bilal] = DuesBaselineTest::seedDuesFixture($this);
        $this->connectWhatsApp($shop);

        $notification = app(CustomerNotifications::class)->paymentReminder($bilal, $owner->id);

        $this->assertFalse($notification->wasSent());
        $this->assertStringContainsString('template is not approved', $notification->error);
        Http::assertNothingSent();
    }

    public function test_reminders_screen_lists_exactly_the_udhaar_totals_and_sends(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $bilal] = DuesBaselineTest::seedDuesFixture($this);
        $this->approveTemplates($this->connectWhatsApp($shop));
        $this->actingAs($owner->fresh());

        Livewire::test('whatsapp-messages.index')
            ->assertViewHas('reminderRows', fn ($rows) => $rows->count() === 1 && (float) $rows->first()['balance'] === 11350.0)
            ->call('previewReminder', $bilal->id)
            ->assertSee('You bought iPhone 13')
            ->call('sendReminder', $bilal->id);

        $this->assertSame(1, CustomerNotification::where('customer_id', $bilal->id)->where('status', 'sent')->count());
        DuesBaselineTest::assertGoldenDues($this, $bilal, Customer::where('name', 'Sana')->first());
    }
}
