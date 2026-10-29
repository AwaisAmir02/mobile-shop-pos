<?php

namespace App\Services\Marketing;

use App\Enums\PostSurface;
use App\Models\MarketingPost;
use App\Models\MarketingPostTarget;
use App\Models\ShopChannelConnection;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaPublisher
{
    public const CONTAINER_POLLS = 5;

    public const CONTAINER_POLL_MS = 1500;

    protected function api(ShopChannelConnection $connection): PendingRequest
    {
        if (! $connection->access_token) {
            throw new RuntimeException('This account is disconnected. Reconnect it in Settings → Integrations.');
        }

        return Http::withToken($connection->access_token)->acceptJson()->timeout(30);
    }

    protected function call(PendingRequest $request, string $method, string $path, array $data = []): array
    {
        $response = $request->{$method}(WhatsAppClient::graphUrl($path), $data);

        if ($response->failed()) {
            throw new RuntimeException($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Meta returned HTTP '.$response->status());
        }

        return $response->json() ?? [];
    }

    public function publish(MarketingPostTarget $target): array
    {
        $post = MarketingPost::withoutGlobalScopes()->findOrFail($target->marketing_post_id);
        $connection = ShopChannelConnection::withoutGlobalScopes()->findOrFail($target->shop_channel_connection_id);
        $imageUrl = $target->surface->isStory() ? $post->storyImageUrl() : $post->imageUrl();

        if ($imageUrl === null || ! str_starts_with($imageUrl, 'https://')) {
            throw new RuntimeException('The post image must be on a public HTTPS address (check APP_URL) for Meta to fetch it.');
        }

        $api = $this->api($connection);
        $caption = (string) $post->caption;

        return match ($target->surface) {
            PostSurface::FacebookFeed => $this->facebookFeed($api, $connection, $imageUrl, $caption),
            PostSurface::FacebookStory => $this->facebookStory($api, $connection, $imageUrl),
            PostSurface::InstagramFeed => $this->instagram($api, $connection, ['image_url' => $imageUrl, 'caption' => $caption]),
            PostSurface::InstagramStory => $this->instagram($api, $connection, ['image_url' => $imageUrl, 'media_type' => 'STORIES']),
        };
    }

    protected function facebookFeed(PendingRequest $api, ShopChannelConnection $connection, string $imageUrl, string $caption): array
    {
        $result = $this->call($api, 'post', $connection->external_id.'/photos', ['url' => $imageUrl, 'caption' => $caption, 'published' => 'true']);
        $id = (string) ($result['post_id'] ?? $result['id']);

        return ['external_id' => $id, 'permalink' => 'https://www.facebook.com/'.$id];
    }

    protected function facebookStory(PendingRequest $api, ShopChannelConnection $connection, string $imageUrl): array
    {
        $photo = $this->call($api, 'post', $connection->external_id.'/photos', ['url' => $imageUrl, 'published' => 'false']);
        $story = $this->call($api, 'post', $connection->external_id.'/photo_stories', ['photo_id' => $photo['id']]);

        return ['external_id' => (string) ($story['post_id'] ?? $photo['id']), 'permalink' => null];
    }

    protected function instagram(PendingRequest $api, ShopChannelConnection $connection, array $media): array
    {
        $container = $this->call($api, 'post', $connection->external_id.'/media', $media);
        $containerId = (string) $container['id'];

        for ($i = 0; $i < self::CONTAINER_POLLS; $i++) {
            $status = $this->call($api, 'get', $containerId, ['fields' => 'status_code'])['status_code'] ?? 'FINISHED';

            if ($status === 'FINISHED') {
                break;
            }

            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException('Instagram could not process the image ('.$status.').');
            }

            usleep(self::CONTAINER_POLL_MS * 1000);
        }

        $published = $this->call($api, 'post', $connection->external_id.'/media_publish', ['creation_id' => $containerId]);
        $mediaId = (string) $published['id'];

        $permalink = null;

        try {
            $permalink = $this->call($api, 'get', $mediaId, ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (RuntimeException) {
        }

        return ['external_id' => $mediaId, 'permalink' => $permalink];
    }

    public function insights(MarketingPostTarget $target): ?array
    {
        $connection = ShopChannelConnection::withoutGlobalScopes()->find($target->shop_channel_connection_id);

        if ($connection === null || ! $connection->access_token || ! $target->external_id) {
            return null;
        }

        $api = $this->api($connection);

        return match ($target->surface) {
            PostSurface::FacebookFeed => $this->facebookFeedInsights($api, $target->external_id),
            PostSurface::InstagramFeed => $this->instagramInsights($api, $target->external_id, ['reach', 'views', 'likes', 'comments', 'shares', 'saved']),
            PostSurface::InstagramStory => $this->instagramInsights($api, $target->external_id, ['reach', 'views', 'replies']),
            PostSurface::FacebookStory => null,
        };
    }

    protected function facebookFeedInsights(PendingRequest $api, string $postId): array
    {
        $post = $this->call($api, 'get', $postId, ['fields' => 'likes.summary(true).limit(0),comments.summary(true).limit(0),shares']);

        $metrics = [
            'likes' => (int) ($post['likes']['summary']['total_count'] ?? 0),
            'comments' => (int) ($post['comments']['summary']['total_count'] ?? 0),
            'shares' => (int) ($post['shares']['count'] ?? 0),
        ];

        try {
            $insights = $this->call($api, 'get', $postId.'/insights', ['metric' => 'post_impressions_unique']);
            $metrics['reach'] = (int) ($insights['data'][0]['values'][0]['value'] ?? 0);
        } catch (RuntimeException) {
        }

        return $metrics;
    }

    protected function instagramInsights(PendingRequest $api, string $mediaId, array $metricNames): array
    {
        $data = $this->call($api, 'get', $mediaId.'/insights', ['metric' => implode(',', $metricNames)])['data'] ?? [];

        return collect($data)->mapWithKeys(fn (array $row) => [
            $row['name'] => (int) ($row['values'][0]['value'] ?? $row['total_value']['value'] ?? 0),
        ])->all();
    }
}
