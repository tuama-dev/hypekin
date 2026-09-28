<?php

namespace App\Actions\Application\Post;

use App\Actions\Application\Post\Concerns\ClassifiesMetricsFailures;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class FetchTikTokMetricsAction
{
    use ClassifiesMetricsFailures;

    private const API_BASE = 'https://open.tiktokapis.com/v2';

    /**
     * Fetch engagement metrics for a published TikTok video.
     *
     * @return array<string, int|null>
     *
     * @throws RuntimeException
     */
    public function fetch(PostTarget $target): array
    {
        $token = $target->socialAccount->access_token;

        if ($token === null || $token === '') {
            throw new RuntimeException('This TikTok account is missing an access token.');
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post(self::API_BASE.'/video/query/', [
                    'filters' => [
                        'video_ids' => [$target->platform_post_id],
                    ],
                    'fields' => ['view_count', 'like_count', 'comment_count', 'share_count'],
                ]);
        } catch (Throwable $exception) {
            $this->transportMetricsFailure('TikTok metrics request failed: '.$exception->getMessage(), $exception);
        }

        if ($response->failed()) {
            $this->respondMetricsFailure($response);
        }

        $video = $response->json('data.videos.0') ?? [];
        $likes = (int) ($video['like_count'] ?? 0);
        $comments = (int) ($video['comment_count'] ?? 0);
        $shares = (int) ($video['share_count'] ?? 0);

        return [
            'likes' => $likes,
            'comments' => $comments,
            'shares' => $shares,
            'saves' => null,
            'impressions' => null,
            'reach' => null,
            'engagements' => null,
            'views' => (int) ($video['view_count'] ?? 0),
        ];
    }
}
