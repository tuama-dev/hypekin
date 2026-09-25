<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk media rows reference and where uploaded files live.
    | Any S3-compatible disk (AWS S3, DigitalOcean Spaces, Cloudflare R2,
    | MinIO) works. Swap providers by changing this value and the matching
    | AWS_* environment variables in .env — no code changes needed.
    |
    */

    'disk' => env('MEDIA_FILESYSTEM_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Upload Types
    |--------------------------------------------------------------------------
    |
    | MIME types accepted for direct uploads. Set to `null` to allow any of
    | the default image and video types below.
    |
    */

    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'video/mp4',
    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum File Size (bytes)
    |--------------------------------------------------------------------------
    |
    | The largest single file a user may upload directly to object storage.
    |
    */

    'max_size' => (int) env('MEDIA_MAX_SIZE', 20 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Presigned Upload Validity (minutes)
    |--------------------------------------------------------------------------
    |
    | How long a presigned PUT URL stays valid before the browser must finish
    | uploading. Large files over slow connections need more runway.
    |
    */

    'presign_ttl' => (int) env('MEDIA_PRESIGN_TTL', 15),

    /*
    |--------------------------------------------------------------------------
    | Object Key Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix under which media objects are stored in the bucket. Keys are
    | always server-generated, never client-supplied.
    |
    */

    'key_prefix' => implode('/', array_filter([
        'media',
        env('APP_ENV', 'local'),
    ])),

];
