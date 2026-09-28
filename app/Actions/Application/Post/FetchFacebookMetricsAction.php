<?php

namespace App\Actions\Application\Post;

use App\Actions\Application\Post\Concerns\ClassifiesMetricsFailures;
use App\Models\PostTarget;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class FetchFacebookMetricsAction
{
    use ClassifiesMetricsFailures;

    private const GRAPH_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Fetch engagement metrics for a published Facebook post.
     *
     * @return array<string, int|null>
     *
     * @throws RuntimeException
     */
    public function fetch(PostTarget $target): array
    {
        $token = $target->socialAccount->access_token;

        if ($token === null || $token === '') {
            throw new RuntimeException('This Facebook page is missing an access token.');
        }

        $postId = $target->platform_post_id;

        if ($postId === null || $postId === '') {
            throw new RuntimeException('This Facebook target has no platform post id.');
        }

        $post = $this->request($token, $target->platform_post_id, [
            'fields' => 'likes.summary(true),comments.summary(true),shares',
        ]);

        $insights = $this->request($token, $target->platform_post_id.'/insights', [
            'metric' => 'post_impressions,post_impressions_unique',
        ]);

        return [
            'likes' => (int) ($post->json('likes.summary.total_count') ?? 0),
            'comments' => (int) ($post->json('comments.summary.total_count') ?? 0),
            'shares' => (int) ($post->json('shares') ?? 0),
            'saves' => null,
            'impressions' => $this->insightValue($insights->json('data'), 'post_impressions'),
            'reach' => $this->insightValue($insights->json('data'), 'post_impressions_unique'),
            'engagements' => null,
            'views' => null,
        ];
    }

    private function insightValue(?array $data, string $name): ?int
    {
        foreach ($data ?? [] as $entry) {
            if (($entry['name'] ?? null) === $name) {
                return (int) ($entry['values'][0]['value'] ?? 0);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $params
     *
     * @throws RuntimeException
     */
    private function request(string $token, string $path, array $params): Response
    {
        try {
            $response = Http::withToken($token)
                ->connectTimeout(10)
                ->timeout(30)
                ->get(self::GRAPH_BASE.'/'.$path, $params);
        } catch (Throwable $exception) {
            $this->transportMetricsFailure('Facebook metrics request failed: '.$exception->getMessage(), $exception);
        }

        if ($response->failed()) {
            $this->respondMetricsFailure($response);
        }

        return $response;
    }
}
