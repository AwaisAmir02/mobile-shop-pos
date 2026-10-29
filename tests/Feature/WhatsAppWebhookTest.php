<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\WebhookEvent;
use App\Models\WhatsAppContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    public function test_verification_handshake_requires_the_admin_verify_token(): void
    {
        $this->whatsappIntegration();

        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')
            ->assertOk()
            ->assertSeeText('12345');

        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')
            ->assertForbidden();
    }

    public function test_an_unsigned_or_wrongly_signed_payload_is_rejected_and_not_stored(): void
    {
        $this->whatsappIntegration();
        [, , $connection] = $this->whatsappShop();

        $this->postWebhook($this->webhookBody($connection->external_id, [$this->textMessage('923001234567', 'hi')]), 'wrong-secret')
            ->assertStatus(401);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_a_signed_message_is_stored_processed_and_answered(): void
    {
        $this->fakeGraph();
        $this->whatsappIntegration();
        [$shop, , $connection] = $this->whatsappShop();

        $this->postWebhook($this->webhookBody(
            $connection->external_id,
            [$this->textMessage('923001234567', 'hi', 'wamid.in.1')],
            [['wa_id' => '923001234567', 'profile' => ['name' => 'Ali']]],
        ))->assertOk();

        $event = WebhookEvent::sole();
        $this->assertSame('wa:msg:wamid.in.1', $event->event_key);
        $this->assertNotNull($event->processed_at);
        $this->assertSame($shop->id, WhatsAppContact::withoutGlobalScopes()->sole()->shop_id);
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/'.config('services.meta.graph_version').'/'.$connection->external_id.'/messages'
            && $request->hasHeader('Authorization', 'Bearer shop-token-'.$connection->external_id));
    }

    public function test_a_redelivered_message_is_processed_only_once(): void
    {
        $this->fakeGraph();
        $this->whatsappIntegration();
        [, , $connection] = $this->whatsappShop();
        $body = $this->webhookBody($connection->external_id, [$this->textMessage('923001234567', 'hi', 'wamid.dup')]);

        $this->postWebhook($body)->assertOk();
        $this->postWebhook($body)->assertOk();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertCount(1, $this->sentBodies());
    }

    public function test_messages_route_to_the_shop_owning_the_number(): void
    {
        $this->fakeGraph();
        $this->whatsappIntegration();
        [$shopA, , $connA] = $this->whatsappShop('Shop A', '111');
        [$shopB, , $connB] = $this->whatsappShop('Shop B', '222');

        $this->postWebhook($this->webhookBody('222', [$this->textMessage('923001234567', 'hi')]))->assertOk();

        $this->assertSame([$shopB->id], WhatsAppContact::withoutGlobalScopes()->pluck('shop_id')->all());
    }

    public function test_a_shop_with_whatsapp_disabled_gets_no_bot_replies(): void
    {
        $this->fakeGraph();
        $this->whatsappIntegration();
        [$shop, , $connection] = $this->whatsappShop();
        Shop::whereKey($shop->id)->update(['whatsapp_enabled' => false]);

        $this->postWebhook($this->webhookBody($connection->external_id, [$this->textMessage('923001234567', 'hi')]))->assertOk();

        $this->assertSame(0, WhatsAppContact::withoutGlobalScopes()->count());
        $this->assertCount(0, $this->sentBodies());
    }

    public function test_a_failing_send_is_recorded_and_retried_by_the_sweep(): void
    {
        $this->whatsappIntegration();
        [, , $connection] = $this->whatsappShop();

        $failing = true;
        Http::fake(function () use (&$failing) {
            return $failing
                ? Http::response(['error' => ['message' => 'boom']], 500)
                : Http::response(['messages' => [['id' => 'wamid.out']]], 200);
        });

        $this->postWebhook($this->webhookBody($connection->external_id, [$this->textMessage('923001234567', 'hi')]))->assertOk();

        $event = WebhookEvent::sole();
        $this->assertNull($event->processed_at);
        $this->assertNotNull($event->error);

        $failing = false;
        $this->travel(2)->minutes();
        $this->artisan('whatsapp:sweep')->assertSuccessful();

        $this->assertNotNull($event->fresh()->processed_at);
        $this->assertNull($event->fresh()->error);
    }
}
