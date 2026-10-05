<?php

namespace App\Actions\Application\Media\Concerns;

/**
 * Identify a MIME type from the leading bytes of a file.
 *
 * The browser picks the Content-Type on a direct-to-storage upload and the
 * client also tells the server which type it is uploading, so neither can be
 * trusted on its own. Comparing the declared type against the real signature
 * is what stops an HTML payload being parked at a .png path and later served
 * from the bucket's origin.
 */
trait SniffsMediaMimeTypes
{
    /**
     * How many leading bytes each signature needs.
     */
    private const int SIGNATURE_LENGTH = 12;

    /**
     * @return string|null The detected MIME type, or null when unrecognised.
     */
    private function sniffMimeType(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'image/gif',
            str_starts_with($head, 'RIFF') && str_starts_with(substr($head, 8, 4), 'WEBP') => 'image/webp',
            substr($head, 4, 4) === 'ftyp' => 'video/mp4',
            default => null,
        };
    }
}
