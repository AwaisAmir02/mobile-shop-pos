<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationProvider;
use App\Jobs\ProcessWhatsAppWebhookEvent;
use App\Models\PlatformIntegration;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $integration = PlatformIntegration::for(IntegrationProvider::WhatsApp);
        $token = (string) $request->query('hub_verify_token', '');

        if ($integration === null
            || $request->query('hub_mode') !== 'subscribe'
            || $integration->webhook_verify_token === null
            || ! hash_equals($integration->webhook_verify_token, $token)) {
            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge', ''), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): Response
    {
        $integration = PlatformIntegration::active(IntegrationProvider::WhatsApp);

        if ($integration === null) {
            return response('Not configured', 404);
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $integration->app_secret);

        if (! hash_equals($expected, (string) $request->header('X-Hub-Signature-256', ''))) {
            return response('Invalid signature', 401);
        }

        foreach ($this->extractEvents($request->json()->all()) as $key => $payload) {
            try {
                $event = WebhookEvent::create([
                    'provider' => IntegrationProvider::WhatsApp->value,
                    'event_key' => $key,
                    'payload' => $payload,
                    'received_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            ProcessWhatsAppWebhookEvent::dispatchAfterResponse($event->id);
        }

        return response('OK', 200);
    }

    protected function extractEvents(array $body): array
    {
        $events = [];

        foreach ($body['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? null;
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                if ($phoneNumberId === null) {
                    continue;
                }

                if ($field === 'messages') {
                    $profiles = collect($value['contacts'] ?? [])->mapWithKeys(
                        fn (array $contact) => [$contact['wa_id'] ?? '' => $contact['profile']['name'] ?? null]
                    );

                    foreach ($value['messages'] ?? [] as $message) {
                        $events['wa:msg:'.$message['id']] = [
                            'kind' => 'message',
                            'phone_number_id' => $phoneNumberId,
                            'profile_name' => $profiles[$message['from'] ?? ''] ?? null,
                            'item' => $message,
                        ];
                    }

                    foreach ($value['statuses'] ?? [] as $status) {
                        if (($status['status'] ?? null) !== 'failed') {
                            continue;
                        }

                        $events['wa:status:'.$status['id'].':failed'] = [
                            'kind' => 'status',
                            'phone_number_id' => $phoneNumberId,
                            'item' => $status,
                        ];
                    }
                }

                if ($field === 'smb_message_echoes') {
                    foreach ($value['message_echoes'] ?? [] as $echo) {
                        $events['wa:echo:'.$echo['id']] = [
                            'kind' => 'echo',
                            'phone_number_id' => $phoneNumberId,
                            'item' => $echo,
                        ];
                    }
                }
            }
        }

        return $events;
    }
}
