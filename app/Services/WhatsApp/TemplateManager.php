<?php

namespace App\Services\WhatsApp;

use App\Models\ShopChannelConnection;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TemplateManager
{
    public function submitMissing(ShopChannelConnection $connection): array
    {
        if (! $connection->waba_id) {
            throw new RuntimeException('This WhatsApp connection has no WhatsApp Business Account ID.');
        }

        $results = ['submitted' => 0, 'failed' => []];

        foreach (TemplateLibrary::definitions() as $key => $definition) {
            foreach (array_keys($definition['body']) as $language) {
                $existing = WhatsAppTemplate::withoutGlobalScopes()
                    ->where('shop_channel_connection_id', $connection->id)
                    ->where('key', $key)
                    ->where('language', $language)
                    ->first();

                if ($existing && in_array($existing->status, ['approved', 'pending'], true)) {
                    continue;
                }

                $response = Http::withToken($connection->access_token)->acceptJson()->timeout(20)
                    ->post(WhatsAppClient::graphUrl($connection->waba_id.'/message_templates'), TemplateLibrary::createPayload($key, $language));

                if ($response->failed()) {
                    $results['failed'][] = $key.' ('.$language.'): '.($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'error');

                    continue;
                }

                WhatsAppTemplate::withoutGlobalScopes()->updateOrCreate(
                    ['shop_channel_connection_id' => $connection->id, 'key' => $key, 'language' => $language],
                    [
                        'shop_id' => $connection->shop_id,
                        'name' => TemplateLibrary::name($key),
                        'category' => $definition['category'],
                        'meta_template_id' => (string) $response->json('id'),
                        'status' => strtolower((string) ($response->json('status') ?? 'pending')),
                        'rejection_reason' => null,
                        'synced_at' => now(),
                    ],
                );

                $results['submitted']++;
            }
        }

        return $results;
    }

    public function sync(ShopChannelConnection $connection): int
    {
        if (! $connection->waba_id) {
            return 0;
        }

        $remote = Http::withToken($connection->access_token)->acceptJson()->timeout(20)
            ->get(WhatsAppClient::graphUrl($connection->waba_id.'/message_templates'), [
                'fields' => 'id,name,language,status,category,rejected_reason',
                'limit' => 200,
            ])->throw()->json('data', []);

        $updated = 0;

        foreach ($remote as $template) {
            $key = collect(array_keys(TemplateLibrary::definitions()))
                ->first(fn (string $k) => TemplateLibrary::name($k) === ($template['name'] ?? null));

            if ($key === null) {
                continue;
            }

            WhatsAppTemplate::withoutGlobalScopes()->updateOrCreate(
                ['shop_channel_connection_id' => $connection->id, 'key' => $key, 'language' => $template['language']],
                [
                    'shop_id' => $connection->shop_id,
                    'name' => $template['name'],
                    'category' => $template['category'] ?? TemplateLibrary::definition($key)['category'],
                    'meta_template_id' => $template['id'] ?? null,
                    'status' => strtolower((string) ($template['status'] ?? 'pending')),
                    'rejection_reason' => ($template['rejected_reason'] ?? 'NONE') !== 'NONE' ? $template['rejected_reason'] : null,
                    'synced_at' => now(),
                ],
            );

            $updated++;
        }

        return $updated;
    }

    public static function approved(ShopChannelConnection $connection, string $key, string $language): ?WhatsAppTemplate
    {
        $templates = WhatsAppTemplate::withoutGlobalScopes()
            ->where('shop_channel_connection_id', $connection->id)
            ->where('key', $key)
            ->where('status', 'approved')
            ->get()
            ->keyBy('language');

        return $templates[$language] ?? $templates['en'] ?? $templates->first();
    }
}
