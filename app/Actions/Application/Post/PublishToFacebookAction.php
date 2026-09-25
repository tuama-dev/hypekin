<?php

namespace App\Actions\Application\Post;

use App\Models\PostTarget;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PublishToFacebookAction
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Publish a post target to a Facebook page, returning the platform post id.
     *
     * @throws RuntimeException
     */
    public function publish(PostTarget $target): string
    {
        $token = $target->socialAccount->access_token;

        if ($token === null || $token === '') {
            throw new RuntimeException('This Facebook page is missing an access token.');
        }

        $media = $target->post->media()->first();

        $endpoint = $media !== null
            ? '/'.$target->socialAccount->external_account_id.'/photos'
            : '/'.$target->socialAccount->external_account_id.'/feed';

        $payload = $media !== null
            ? ['url' => $media->publicUrl(), 'caption' => $target->caption]
            : ['message' => $target->caption];

        return $this->post($token, $endpoint, $payload);
    }

    /**
     * @param  array<string, string>  $payload
     *
     * @throws RuntimeException
     */
    private function post(string $token, string $endpoint, array $payload): string
    {
        try {
            $response = Http::withToken($token)
                ->connectTimeout(10)
                ->timeout(30)
                ->asForm()
                ->post(self::GRAPH_BASE.$endpoint, $payload);
        } catch (Throwable $exception) {
            throw new RuntimeException('Facebook request failed: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        $id = $response->json('id');

        if ($id === null) {
            throw new RuntimeException('Facebook did not return a post id.');
        }

        return (string) $id;
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        return $message !== null
            ? $message
            : 'Facebook denied the request ('.$response->status().').';
    }
}
