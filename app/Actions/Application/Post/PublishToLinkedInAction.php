<?php

namespace App\Actions\Application\Post;

use App\Models\Media;
use App\Models\PostTarget;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PublishToLinkedInAction
{
    private const API_BASE = 'https://api.linkedin.com/v2';

    /**
     * Publish a post target to a LinkedIn member profile, returning the share URN.
     *
     * Text-only posts use the UGC endpoint directly. Image posts upload the
     * file to LinkedIn's asset service first (LinkedIn can not publish from an
     * external URL), then share the uploaded asset.
     *
     * @throws RuntimeException
     */
    public function publish(PostTarget $target): string
    {
        $token = $target->socialAccount->access_token;

        if ($token === null || $token === '') {
            throw new RuntimeException('This LinkedIn account is missing an access token.');
        }

        $owner = 'urn:li:person:'.$target->socialAccount->external_account_id;
        $media = $target->post->media()->first();

        return $media !== null
            ? $this->publishImage($token, $owner, $target, $media)
            : $this->publishText($token, $owner, $target->caption);
    }

    /**
     * @throws RuntimeException
     */
    private function publishText(string $token, string $owner, string $caption): string
    {
        return $this->createUgcPost($token, $owner, [
            'shareCommentary' => ['text' => $caption],
            'shareMediaCategory' => 'NONE',
        ]);
    }

    /**
     * @throws RuntimeException
     */
    private function publishImage(string $token, string $owner, PostTarget $target, Media $media): string
    {
        $asset = $this->registerUpload($token, $owner, $media);

        return $this->createUgcPost($token, $owner, [
            'shareCommentary' => ['text' => $target->caption],
            'shareMediaCategory' => 'IMAGE',
            'media' => [[
                'status' => 'READY',
                'description' => ['text' => $target->caption],
                'media' => $asset,
            ]],
        ]);
    }

    /**
     * Register an upload slot and stream the media bytes into it.
     *
     * @throws RuntimeException
     */
    private function registerUpload(string $token, string $owner, Media $media): string
    {
        $response = $this->request('POST', self::API_BASE.'/assets?action=registerUpload', $token, [
            'registerUploadRequest' => [
                'recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
                'owner' => $owner,
                'serviceRelationships' => [[
                    'relationshipType' => 'OWNER',
                    'identifier' => 'urn:li:userGeneratedContent',
                ]],
            ],
        ]);

        $uploadUrl = Arr::first($response->json('value.uploadMechanism'))['uploadUrl'] ?? null;
        $asset = $response->json('value.asset');

        if ($uploadUrl === null || $asset === null) {
            throw new RuntimeException('LinkedIn did not return an upload slot.');
        }

        try {
            $stream = Storage::disk($media->disk)->readStream($media->path);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to read the media file for upload.', 0, $exception);
        }

        if ($stream === false) {
            throw new RuntimeException('Unable to read the media file for upload.');
        }

        try {
            $upload = Http::withToken($token)
                ->withHeaders(['Content-Type' => $media->mime_type])
                ->connectTimeout(10)
                ->timeout(120)
                ->send('PUT', $uploadUrl, ['body' => $stream]);
        } catch (Throwable $exception) {
            throw new RuntimeException('LinkedIn upload failed: '.$exception->getMessage(), 0, $exception);
        } finally {
            fclose($stream);
        }

        if ($upload->failed()) {
            throw new RuntimeException($this->errorMessage($upload));
        }

        return $asset;
    }

    /**
     * @param  array<string, mixed>  $shareContent
     *
     * @throws RuntimeException
     */
    private function createUgcPost(string $token, string $owner, array $shareContent): string
    {
        $response = $this->request('POST', self::API_BASE.'/ugcPosts', $token, [
            'author' => $owner,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => $shareContent,
            ],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
        ]);

        $shareId = $response->json('id');

        if ($shareId === null) {
            throw new RuntimeException('LinkedIn did not return a share id.');
        }

        return (string) $shareId;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException
     */
    private function request(string $method, string $url, string $token, array $payload): Response
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->connectTimeout(10)
                ->timeout(30)
                ->send($method, $url, ['json' => $payload]);
        } catch (Throwable $exception) {
            throw new RuntimeException('LinkedIn request failed: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response;
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('message');

        return $message !== null
            ? $message
            : 'LinkedIn denied the request ('.$response->status().').';
    }
}
