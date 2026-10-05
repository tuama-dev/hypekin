<?php

namespace App\Actions\Application\Media;

use Aws\S3\PostObjectV4;
use Aws\S3\S3Client;
use DateTimeInterface;
use RuntimeException;

/**
 * Sign a direct browser → object storage upload as a presigned POST policy.
 *
 * A presigned PUT cannot pin the request's Content-Type: the AWS SDK strips it
 * from the signature because the uploader chooses it at send time
 * (Aws\Signature\SignatureV4::getPresignHeaderDenyList). That left a stored XSS
 * gap — a GIF-header payload uploaded as `text/html` passed byte sniffing yet
 * was served from the bucket origin as HTML.
 *
 * A POST policy fixes that at the source: `['eq', '$Content-Type', ...]` makes
 * S3 itself reject any upload whose header differs, so the type stored on the
 * object is always one of the allow-listed media types.
 */
class CreateMediaUploadPostAction
{
    /**
     * @return array{url: string, fields: array<string, string>}
     */
    public function execute(string $path, string $mimeType, DateTimeInterface $expiresAt): array
    {
        $disk = $this->s3Disk();
        $bucket = (string) $disk['bucket'];

        $post = new PostObjectV4(
            $this->client($disk),
            $bucket,
            // `key` must be passed here: PostObjectV4 otherwise falls back to
            // the literal `${filename}`, handing key selection to the browser.
            ['key' => $path],
            [
                ['bucket' => $bucket],
                ['eq', '$key', $path],
                ['eq', '$Content-Type', $mimeType],
                ['content-length-range', 1, (int) config('media.max_size')],
            ],
            $expiresAt,
        );

        return [
            'url' => $post->getFormAttributes()['action'],
            'fields' => $post->getFormInputs(),
        ];
    }

    /**
     * Mirror the configured disk's S3 settings so provider swaps stay a config
     * change rather than a code change.
     *
     * @param  array<string, mixed>  $disk
     */
    private function client(array $disk): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $disk['region'] ?? 'us-east-1',
            'credentials' => [
                'key' => $disk['key'] ?? null,
                'secret' => $disk['secret'] ?? null,
            ],
            'endpoint' => $disk['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($disk['use_path_style_endpoint'] ?? false),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function s3Disk(): array
    {
        $name = (string) config('media.disk');
        $disk = config("filesystems.disks.{$name}");

        if (! is_array($disk) || ($disk['driver'] ?? null) !== 's3') {
            throw new RuntimeException(
                "Direct media uploads need an s3 disk, but the media disk [{$name}] is "
                .(is_array($disk) ? 'a '.($disk['driver'] ?? 'unknown').' disk' : 'not configured')
                .'. Set MEDIA_FILESYSTEM_DISK to an s3 disk — upload bytes are never proxied '
                .'through the application.'
            );
        }

        return $disk;
    }
}
