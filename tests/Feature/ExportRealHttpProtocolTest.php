<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Livewire::test()->call('exportPdf') calls the component method directly
 * in-process and can mask real-request bugs. This test instead drives the
 * REAL HTTP wire protocol end to end (GET the page, parse the embedded
 * snapshot, POST to /livewire/update exactly like the browser's JS does)
 * to see the raw response a real click would receive.
 */
class ExportRealHttpProtocolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_real_livewire_update_request_for_export_pdf(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);

        $this->actingAs($owner);

        $page = $this->get(route('sales.history'));
        $html = $page->getContent();

        $snapshotJson = $this->findSnapshotFor($html, 'sales.history');

        $payload = [
            '_token' => csrf_token(),
            'components' => [
                [
                    'snapshot' => $snapshotJson,
                    'updates' => [],
                    'calls' => [
                        ['path' => '', 'method' => 'exportPdf', 'params' => []],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/livewire/update', $payload);

        dump('STATUS: '.$response->getStatusCode());
        dump('BODY: '.substr($response->getContent(), 0, 3000));

        $response->assertOk();

        $body = $response->json();
        $effects = $body['components'][0]['effects'] ?? [];

        $this->assertArrayHasKey('download', $effects, 'Expected a "download" effect in the response — got: '.json_encode(array_keys($effects)));
    }

    public function test_the_real_livewire_update_request_for_export_excel(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);

        $this->actingAs($owner);

        $page = $this->get(route('sales.history'));
        $html = $page->getContent();

        $snapshotJson = $this->findSnapshotFor($html, 'sales.history');

        $payload = [
            '_token' => csrf_token(),
            'components' => [
                [
                    'snapshot' => $snapshotJson,
                    'updates' => [],
                    'calls' => [
                        ['path' => '', 'method' => 'exportExcel', 'params' => []],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/livewire/update', $payload);

        dump('STATUS: '.$response->getStatusCode());
        dump('BODY: '.substr($response->getContent(), 0, 3000));

        $response->assertOk();

        $body = $response->json();
        $effects = $body['components'][0]['effects'] ?? [];

        $this->assertArrayHasKey('download', $effects, 'Expected a "download" effect in the response — got: '.json_encode(array_keys($effects)));
    }

    /** The page embeds multiple wire:snapshot components (nav, sidebar, the page itself) — find the one we want. */
    protected function findSnapshotFor(string $html, string $componentName): string
    {
        preg_match_all('/wire:snapshot="(.*?)"\s/s', $html, $matches);

        foreach ($matches[1] as $raw) {
            $json = html_entity_decode($raw);
            $snapshot = json_decode($json, true);

            if (($snapshot['memo']['name'] ?? null) === $componentName) {
                return $json;
            }
        }

        $this->fail("Could not find a wire:snapshot for component [{$componentName}] on the page.");
    }
}
