<?php

namespace App\Actions\Application\Post;

use App\Models\PostTarget;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PublishToInstagramAction
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Publish a post target to an Instagram business account, returning the
     * published media id.
     *
     * Instagram publishing is two-step: first create an image container, then
     * publish it once a creation id is returned.
     *
     * @throws RuntimeException
     */
    public function publish(PostTarget $target): string
    {
        $token = $target->socialAccount->access_token;

        if ($token === null || $token === '') {
            throw new RuntimeException('This Instagram account is missing an access token.');
        }

        $media = $target->post->media()->first();

        if ($media === null) {
            throw new RuntimeException('Instagram posts require an image.');
        }

        $accountId = $target->socialAccount->external_account_id;

        $creationId = $this->createContainer($token, $accountId, $media->publicUrl(), $target->caption);

        return $this->publishContainer($token, $accountId, $creationId);
    }

    /**
     * @throws RuntimeException
     */
    private function createContainer(string $token, string $accountId, string $imageUrl, string $caption): string
    {
        try {
            $response = Http::withToken($token)
                ->connectTimeout(10)
                ->timeout(30)
                ->asForm()
                ->post(self::GRAPH_BASE.'/'.$accountId.'/media', [
                    'image_url' => $imageUrl,
                    'caption' => $caption,
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Instagram request failed: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        $creationId = $response->json('id');

        if ($creationId === null) {
            throw new RuntimeException('Instagram did not return a creation id.');
        }

        return (string) $creationId;
    }

    /**
     * @throws RuntimeException
     */
    private function publishContainer(string $token, string $accountId, string $creationId): string
    {
        try {
            $response = Http::withToken($token)
                ->connectTimeout(10)
                ->timeout(30)
                ->asForm()
                ->post(self::GRAPH_BASE.'/'.$accountId.'/media_publish', [
                    'creation_id' => $creationId,
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Instagram publish failed: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        $publishedId = $response->json('id');

        if ($publishedId === null) {
            throw new RuntimeException('Instagram did not return a published media id.');
        }

        return (string) $publishedId;
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        return $message !== null
            ? $message
            : 'Instagram denied the request ('.$response->status().').';
    }
}
