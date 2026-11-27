<?php

namespace Tests\Feature;

use App\Actions\Appointments;
use App\Actions\JobCards;
use App\Actions\ServiceReminders;
use App\Enums\ChannelProvider;
use App\Enums\ConnectionStatus;
use App\Enums\NotificationType;
use App\Models\CustomerNotification;
use App\Models\Membership;
use App\Models\ShopChannelConnection;
use App\Services\WhatsApp\CustomerNotifications;
use App\Services\WhatsApp\TemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ServiceTradeFixtures;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

/** Automatic WhatsApp reminders for bookings, renewals and service due, with the manual link kept. */
class ServiceRemindersTest extends TestCase
{
    use RefreshDatabase, ServiceTradeFixtures, WhatsAppFixtures;

    protected ShopChannelConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openServiceShop('salon', ['whatsapp_messaging_enabled' => true]);
        $this->connection = ShopChannelConnection::create(['shop_id' => $this->shop->id, 'provider' => ChannelProvider::WhatsApp, 'external_id' => '100200300', 'waba_id' => '900',
            'display_name' => 'Glow WA', 'access_token' => 'token', 'status' => ConnectionStatus::Connected, 'connected_at' => now()]);
        $this->approveTemplates($this->connection);
        $this->fakeGraph();
    }

    protected function send(): array
    {
        return ServiceReminders::sendDue(app(CustomerNotifications::class));
    }

    public function test_the_three_templates_are_defined_like_the_others(): void
    {
        foreach (['appointment_reminder', 'membership_renewal', 'service_due'] as $key) {
            $payload = TemplateLibrary::createPayload($key, 'ur');
            $this->assertSame('hok_'.$key.'_v1', $payload['name']);
            $this->assertSame('UTILITY', $payload['category']);
            preg_match_all('/\{\{\d\}\}/', TemplateLibrary::definition($key)['body']['en'], $m);
            $this->assertCount(count(TemplateLibrary::definition($key)['examples']), array_unique($m[0]));
        }
    }

    public function test_a_booking_tomorrow_is_reminded_once_by_template(): void
    {
        $soon = Appointments::book(['customer_id' => $this->client->id, 'employee_id' => $this->ali->id, 'date' => '2026-11-11', 'time' => '10:00'], [['service_item_id' => $this->haircut->id]], $this->shop->id, null);
        Appointments::book(['customer_id' => $this->client->id, 'employee_id' => $this->ali->id, 'date' => '2026-11-14', 'time' => '10:00'], [['service_item_id' => $this->haircut->id]], $this->shop->id, null);
        Appointments::book(['customer_name' => 'Walk-in', 'phone' => '03001112223', 'date' => '2026-11-11', 'time' => '11:00'], [['service_item_id' => $this->haircut->id]], $this->shop->id, null);

        $this->assertSame(['appointments' => 1, 'renewals' => 0, 'service' => 0], $this->send());
        $this->assertSame(['appointments' => 0, 'renewals' => 0, 'service' => 0], $this->send());
        $this->assertNotNull($soon->fresh()->reminded_at);

        $sent = collect($this->sentBodies())->last();
        $this->assertSame('template', $sent['type']);
        $this->assertSame('hok_appointment_reminder_v1', $sent['template']['name']);
        $this->assertSame(NotificationType::AppointmentReminder, CustomerNotification::latest('id')->first()->type);
    }

    public function test_a_membership_ending_soon_is_reminded_unless_renewed(): void
    {
        $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], paid: 5000);
        $other = \App\Models\Customer::create(['name' => 'Renewed', 'phone' => '03111111111']);
        $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], customer: $other, paid: 5000);
        $this->travelTo(now()->addDays(25));
        $this->bill([['kind' => 'membership', 'membership_plan_id' => $this->monthly->id]], customer: $other, paid: 5000);
        $this->travelTo(now()->addDays(2));

        // Hina's ends 9 Dec, within 3 days; the other customer already renewed from 10 Dec.
        $due = ServiceReminders::dueRenewals();
        $this->assertSame([$this->client->id], $due->pluck('customer_id')->all());
        $this->assertSame(['appointments' => 0, 'renewals' => 1, 'service' => 0], $this->send());
        $this->assertSame('hok_membership_renewal_v1', collect($this->sentBodies())->last()['template']['name']);
        $this->assertNotNull(Membership::where('customer_id', $this->client->id)->first()->renewal_reminded_at);
        $this->assertStringContainsString('ends on 09 Dec 2026', ServiceReminders::renewalText(Membership::where('customer_id', $this->client->id)->first(), 'Glow'));
    }

    public function test_service_due_and_switched_off_shops(): void
    {
        $workshop = \App\Models\Shop::create(['name' => 'Khan Autos', 'business_category' => 'repair_workshop', 'whatsapp_messaging_enabled' => true]);
        $this->assertTrue($workshop->service_reminders_enabled);
        $job = JobCards::open(['customer_id' => $this->client->id, 'next_service_days' => 90], [['kind' => 'bike', 'label' => 'CD70', 'details' => ['reg_no' => 'LEB-1']]], $workshop->id, null);
        JobCards::billed($job, $this->bill([$this->serviceLine($this->haircut)], paid: 800));
        $this->assertSame('2027-02-08', $job->fresh()->service_due_on->toDateString());
        $this->assertCount(0, ServiceReminders::dueServices());

        $this->travelTo(now()->addDays(90));
        $this->assertCount(1, ServiceReminders::dueServices());
        $workshop->update(['service_reminders_enabled' => false]);
        $this->assertCount(0, ServiceReminders::dueServices());

        // As the scheduled command runs: nobody signed in.
        auth()->logout();
        $this->assertStringContainsString('CD70 (LEB-1) is due for its next service', ServiceReminders::serviceDueText($job->fresh(), 'Khan Autos'));
    }

    public function test_shops_without_whatsapp_messaging_get_none_and_keep_the_manual_link(): void
    {
        $this->shop->update(['whatsapp_messaging_enabled' => false]);
        Appointments::book(['customer_id' => $this->client->id, 'employee_id' => $this->ali->id, 'date' => '2026-11-11', 'time' => '10:00'], [['service_item_id' => $this->haircut->id]], $this->shop->id, null);

        $this->assertCount(0, ServiceReminders::dueAppointments());
        $this->get(route('appointments.index', ['date' => '2026-11-11']))->assertOk()->assertSee('https://wa.me/923001234567', false);
    }

    public function test_the_switch_on_customer_messages(): void
    {
        \Livewire\Volt\Volt::test('whatsapp-messages.index')->assertSee('data-service-reminders', false)->call('toggleServiceReminders');
        $this->assertFalse($this->shop->fresh()->service_reminders_enabled);
    }
}
