<?php

namespace Tests\Feature;

use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\TemplateLibrary;
use App\Services\WhatsApp\TemplateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppTemplateTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    public function test_submitting_creates_each_template_in_both_languages_with_examples(): void
    {
        [, , $connection] = $this->whatsappShop();
        Http::fake(['graph.facebook.com/*/message_templates' => Http::response(['id' => 'tpl-1', 'status' => 'PENDING'], 200)]);

        $result = app(TemplateManager::class)->submitMissing($connection);

        $expected = count(TemplateLibrary::definitions()) * 2;
        $this->assertSame($expected, $result['submitted']);
        $this->assertSame($expected, WhatsAppTemplate::count());
        Http::assertSent(fn ($r) => $r['name'] === 'hok_review_request_v1' && $r['language'] === 'ur'
            && $r['components'][1]['buttons'][0]['text'] === 'Zabardast'
            && count($r['components'][0]['example']['body_text'][0]) === 3);

        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'x'], 200)]);
        $this->assertSame(0, app(TemplateManager::class)->submitMissing($connection)['submitted']);
    }

    public function test_sync_updates_status_and_rejection_reason(): void
    {
        [, , $connection] = $this->whatsappShop();
        Http::fake(['graph.facebook.com/*/message_templates*' => Http::response(['data' => [
            ['id' => '1', 'name' => 'hok_payment_reminder_v1', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY'],
            ['id' => '2', 'name' => 'hok_restock_alert_v1', 'language' => 'ur', 'status' => 'REJECTED', 'category' => 'MARKETING', 'rejected_reason' => 'INVALID_FORMAT'],
            ['id' => '3', 'name' => 'someone_elses_template', 'language' => 'en', 'status' => 'APPROVED'],
        ]], 200)]);

        $this->assertSame(2, app(TemplateManager::class)->sync($connection));

        $this->assertTrue(WhatsAppTemplate::where('key', 'payment_reminder')->sole()->isApproved());
        $rejected = WhatsAppTemplate::where('key', 'restock_alert')->sole();
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('INVALID_FORMAT', $rejected->rejection_reason);
        $this->assertSame('MARKETING', $rejected->category);
        $this->assertNotNull(TemplateManager::approved($connection, 'payment_reminder', 'ur'));
        $this->assertNull(TemplateManager::approved($connection, 'restock_alert', 'ur'));
    }
}
