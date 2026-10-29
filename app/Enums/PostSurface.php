<?php

namespace App\Enums;

enum PostSurface: string
{
    case FacebookFeed = 'fb_feed';
    case FacebookStory = 'fb_story';
    case InstagramFeed = 'ig_feed';
    case InstagramStory = 'ig_story';

    public function label(): string
    {
        return match ($this) {
            self::FacebookFeed => 'Facebook post',
            self::FacebookStory => 'Facebook story',
            self::InstagramFeed => 'Instagram post',
            self::InstagramStory => 'Instagram story',
        };
    }

    public function provider(): ChannelProvider
    {
        return match ($this) {
            self::FacebookFeed, self::FacebookStory => ChannelProvider::Facebook,
            self::InstagramFeed, self::InstagramStory => ChannelProvider::Instagram,
        };
    }

    public function isStory(): bool
    {
        return in_array($this, [self::FacebookStory, self::InstagramStory], true);
    }
}
