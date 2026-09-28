<?php

namespace App\Actions\Application\Post;

use App\Models\PostTarget;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PublishToTikTokAction
{
    private const API_BASE = 'https://open.tiktokapis.com/v2';

    private const MAX_TITLE_LENGTH = 22;

    /**
     * Register a TikTok photo post for publishing and return the publish id.
     *
     * TikTok publishing is asynchronous: after init returns a publish id the
     * post is delivered in the background and its outcome is tracked by
     * polling the publish status endpoint.
     *
     * @throws RuntimeException
     */
    public function initialize(PostTarget $target): string
    {
        $token = $target->socialAccount->access_token;

        if ($token === null || $token === '') {
            throw new RuntimeException('This TikTok account is missing an access token.');
        }

        $media = $target->post->media()->first();

        if ($media === null) {
            throw new RuntimeException('TikTok posts require an image.');
        }

        $title = $target->title !== null && $target->title !== ''
            ? Str::limit($target->title, self::MAX_TITLE_LENGTH, '')
            : Str::limit($target->caption, self::MAX_TITLE_LENGTH, '');

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post(self::API_BASE.'/post/publish/content/init/', [
                    'post_info' => [
                        'title' => $title,
                        'description' => $target->caption,
                        'privacy_level' => 'PUBLIC_TO_EVERYONE',
                        'disable_comment' => false,
                    ],
                    'source_info' => [
                        'source' => 'PULL_FROM_URL',
                        'photo_cover_index' => 0,
                        'photo_images' => [$media->publicUrl()],
                    ],
                    'post_mode' => 'DIRECT_POST',
                    'media_type' => 'PHOTO',
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException('TikTok request failed: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        $publishId = $response->json('data.publish_id');

        if ($publishId === null) {
            throw new RuntimeException('TikTok did not return a publish id.');
        }

        return (string) $publishId;
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message') ?? $response->json('message');

        return $message !== null
            ? $message
            : 'TikTok denied the request ('.$response->status().').';
    }
}
