<?php

namespace App\Actions\Application\Post;

use App\Actions\Application\Post\Concerns\ClassifiesMetricsFailures;
use App\Models\PostTarget;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class FetchInstagramMetricsAction
{
    use ClassifiesMetricsFailures;

    private const GRAPH_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Fetch engagement metrics for a published Instagram media.
     *
     * @return array<string, int|null>
     *
     * @throws RuntimeException
     */
    public function fetch(PostTarget $target): array
    {
        $token = $target->socialAccount->access_token;

        if ($token === null || $token === '') {
            throw new RuntimeException('This Instagram account is missing an access token.');
        }

        $response = $this->request($token, $target->platform_post_id.'/insights', [
            'metric' => 'likes,comments,shares,saves,reach,impressions',
        ]);

        $data = $response->json('data');

        return [
            'likes' => $this->insightValue($data, 'likes'),
            'comments' => $this->insightValue($data, 'comments'),
            'shares' => $this->insightValue($data, 'shares'),
            'saves' => $this->insightValue($data, 'saves'),
            'impressions' => $this->insightValue($data, 'impressions'),
            'reach' => $this->insightValue($data, 'reach'),
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
            $this->transportMetricsFailure('Instagram metrics request failed: '.$exception->getMessage(), $exception);
        }

        if ($response->failed()) {
            $this->respondMetricsFailure($response);
        }

        return $response;
    }
}
