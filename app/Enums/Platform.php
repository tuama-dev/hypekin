<?php

namespace App\Enums;

enum Platform: string
{
    case LinkedIn = 'linkedin';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Tiktok = 'tiktok';

    public function label(): string
    {
        return match ($this) {
            self::LinkedIn => 'LinkedIn',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::Tiktok => 'TikTok',
        };
    }

    /**
     * The platforms that can be published to from the composer.
     *
     * @return list<self>
     */
    public static function publishable(): array
    {
        return [self::Facebook, self::Instagram, self::LinkedIn, self::Tiktok];
    }

    /**
     * The Socialite driver used to connect this platform.
     */
    public function socialiteDriver(): string
    {
        return match ($this) {
            self::LinkedIn => 'linkedin',
            self::Facebook => 'facebook-posting',
            self::Instagram => 'instagram',
            self::Tiktok => 'tiktok',
        };
    }

    /**
     * The OAuth scopes requested when connecting this platform.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::LinkedIn => ['r_liteprofile', 'w_member_social'],
            self::Facebook => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
            self::Instagram => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish'],
            self::Tiktok => ['user.info.basic', 'video.publish'],
        };
    }
}
