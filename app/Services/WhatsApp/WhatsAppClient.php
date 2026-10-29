<?php

namespace App\Services\WhatsApp;

use App\Models\ShopChannelConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class WhatsAppClient
{
    public function __construct(protected ShopChannelConnection $connection)
    {
    }

    public static function for(ShopChannelConnection $connection): self
    {
        return new self($connection);
    }

    public static function graphUrl(string $path = ''): string
    {
        return 'https://graph.facebook.com/'.config('services.meta.graph_version').'/'.ltrim($path, '/');
    }

    protected function request(): PendingRequest
    {
        return Http::withToken($this->connection->access_token)
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 300, fn ($e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false);
    }

    public function send(string $to, array $message): ?string
    {
        $response = $this->request()->post(self::graphUrl($this->connection->external_id.'/messages'), array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
        ], $message));

        $response->throw();

        return $response->json('messages.0.id');
    }

    public function sendText(string $to, string $body): ?string
    {
        return $this->send($to, [
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    public function sendButtons(string $to, string $body, array $buttons, ?string $header = null): ?string
    {
        $interactive = [
            'type' => 'button',
            'body' => ['text' => $body],
            'action' => [
                'buttons' => collect($buttons)->take(3)->map(fn (string $title, string $id) => [
                    'type' => 'reply',
                    'reply' => ['id' => $id, 'title' => mb_substr($title, 0, 20)],
                ])->values()->all(),
            ],
        ];

        if ($header !== null) {
            $interactive['header'] = ['type' => 'text', 'text' => mb_substr($header, 0, 60)];
        }

        return $this->send($to, ['type' => 'interactive', 'interactive' => $interactive]);
    }

    public function sendList(string $to, string $body, string $buttonLabel, array $rows, string $sectionTitle = 'Products'): ?string
    {
        return $this->send($to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $body],
                'action' => [
                    'button' => mb_substr($buttonLabel, 0, 20),
                    'sections' => [[
                        'title' => mb_substr($sectionTitle, 0, 24),
                        'rows' => collect($rows)->take(10)->map(fn (array $row) => array_filter([
                            'id' => $row['id'],
                            'title' => mb_substr($row['title'], 0, 24),
                            'description' => isset($row['description']) ? mb_substr($row['description'], 0, 72) : null,
                        ]))->values()->all(),
                    ]],
                ],
            ],
        ]);
    }

    public function sendTemplate(string $to, string $name, string $language, array $bodyParams, array $buttonPayloads = []): ?string
    {
        $components = [];

        if ($bodyParams !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => TemplateLibrary::sanitizeParam((string) $value)], array_values($bodyParams)),
            ];
        }

        foreach (array_values($buttonPayloads) as $index => $payload) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => (string) $index,
                'parameters' => [['type' => 'payload', 'payload' => $payload]],
            ];
        }

        return $this->send($to, [
            'type' => 'template',
            'template' => array_filter([
                'name' => $name,
                'language' => ['code' => $language],
                'components' => $components ?: null,
            ]),
        ]);
    }

    public function sendImage(string $to, string $link, ?string $caption = null): ?string
    {
        return $this->send($to, [
            'type' => 'image',
            'image' => array_filter(['link' => $link, 'caption' => $caption]),
        ]);
    }

    public function uploadMedia(string $contents, string $filename, string $mimeType): string
    {
        $response = Http::withToken($this->connection->access_token)
            ->timeout(30)
            ->attach('file', $contents, $filename, ['Content-Type' => $mimeType])
            ->post(self::graphUrl($this->connection->external_id.'/media'), [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]);

        $response->throw();

        return (string) $response->json('id');
    }

    public function sendDocument(string $to, string $mediaId, string $filename, ?string $caption = null): ?string
    {
        return $this->send($to, [
            'type' => 'document',
            'document' => array_filter(['id' => $mediaId, 'filename' => $filename, 'caption' => $caption]),
        ]);
    }

    public function markRead(string $messageId): void
    {
        $this->request()->post(self::graphUrl($this->connection->external_id.'/messages'), [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);
    }
}
