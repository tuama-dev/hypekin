<?php

namespace App\Actions\Application\Post;

use App\Actions\Application\Post\Concerns\ClassifiesMetricsFailures;
use App\Models\PostTarget;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class FetchLinkedInMetricsAction
{
    use ClassifiesMetricsFailures;

    private const API_BASE = 'https://api.linkedin.com/rest';

    /**
     * Fetch engagement metrics for a published LinkedIn share.
     *
     * @return array<string, int|null>
     *
     * @throws RuntimeException
     */
    public function fetch(PostTarget $target): array
    {
        $token = $target->socialAccount->access_token;

        if ($token === null || $token === '') {
            throw new RuntimeException('This LinkedIn account is missing an access token.');
        }

        $analytics = $this->request($token, $target->platform_post_id);
        $element = $analytics->json('elements.0') ?? [];

        $likes = (int) ($element['likeCount'] ?? 0);
        $comments = (int) ($element['commentCount'] ?? 0);
        $shares = (int) ($element['shareCount'] ?? 0);

        return [
            'likes' => $likes,
            'comments' => $comments,
            'shares' => $shares,
            'saves' => null,
            'impressions' => (int) ($element['impressionCount'] ?? 0),
            'reach' => null,
            'engagements' => $likes + $comments + $shares,
            'views' => null,
        ];
    }

    /**
     * @throws RuntimeException
     */
    private function request(string $token, string $shareId): Response
    {
        $start = now()->startOfDay()->subDays(6)->getTimestamp() * 1000;
        $end = now()->addDay()->getTimestamp() * 1000;

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->connectTimeout(10)
                ->timeout(30)
                ->get(self::API_BASE.'/socialActions/'.$shareId.'/analytics', [
                    'timeIntervals' => sprintf('(timeRange:(start:%d,end:%d),timeGranularityType:DAY)', $start, $end),
                ]);
        } catch (Throwable $exception) {
            $this->transportMetricsFailure('LinkedIn metrics request failed: '.$exception->getMessage(), $exception);
        }

        if ($response->failed()) {
            $this->respondMetricsFailure($response);
        }

        return $response;
    }
}
