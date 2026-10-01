<?php

namespace Database\Seeders;

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\SocialAccountStatus;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostMetric;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PostTargetFailedNotification;
use App\Settings\Settings;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Seed a demo owner with accounts, media and posts so every application page
 * has a realistic non-empty state.
 *
 * Posts cover every status and are spread across the previous, current and
 * next month so the calendar grid shows dots while panning. Seeded images are
 * written to the public disk and served through the storage symlink, so
 * thumbnails resolve without object-storage credentials.
 *
 * Re-running the seeder refreshes the accounts and media in place and resets
 * the demo workspace's posts (targets and media pivots cascade) rather than
 * duplicating content.
 */
class DemoContentSeeder extends Seeder
{
    use WithoutModelEvents;

    private const USER_EMAIL = 'demo@dev.com';

    private const USER_PASSWORD = 'password';

    private const MEDIA_DISK = 'public';

    private const METRICS_WINDOW_DAYS = 30;

    public function run(): void
    {
        $user = $this->createUser();
        $workspace = app(CreateWorkspaceAction::class)->ensure($user);

        $accounts = $this->createSocialAccounts($workspace, $user);
        $media = $this->createMedia($workspace, $user);
        $this->createPosts($workspace, $user, $accounts, $media);
        $this->createMetrics($workspace, $user);
        $this->seedFailureNotification($workspace, $user);
        $this->seedRetryAttempts($workspace, $user);

        $this->command?->info(sprintf(
            'Demo content seeded. Log in as %s / %s at %s',
            self::USER_EMAIL,
            self::USER_PASSWORD,
            route('workspace.dashboard', ['workspace' => $workspace->slug]),
        ));

        if (! file_exists(public_path('storage'))) {
            $this->command?->warn('Run "php artisan storage:link" so the seeded media resolves.');
        }
    }

    private function createUser(): User
    {
        return User::updateOrCreate(
            ['email' => self::USER_EMAIL],
            [
                'fullname' => 'Dana Demo',
                'password' => self::USER_PASSWORD,
                'email_verified_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, SocialAccount>
     */
    private function createSocialAccounts(Workspace $workspace, User $user): array
    {
        $definitions = [
            'facebook_main' => [
                'platform' => Platform::Facebook,
                'external_account_id' => 'acme-studio-facebook',
                'display_name' => 'Acme Studio',
                'status' => SocialAccountStatus::Connected,
                'token_expires_at' => now()->addDays(58),
                'connected_at' => now()->subDays(120),
                'avatar' => '#1877F2',
            ],
            'facebook_shop' => [
                'platform' => Platform::Facebook,
                'external_account_id' => 'acme-coffee-facebook',
                'display_name' => 'Acme Coffee Co',
                'status' => SocialAccountStatus::Connected,
                'token_expires_at' => now()->addDays(41),
                'connected_at' => now()->subDays(64),
                'avatar' => '#1264A3',
            ],
            'instagram_main' => [
                'platform' => Platform::Instagram,
                'external_account_id' => 'acme-studio-instagram',
                'display_name' => '@acme.studio',
                'status' => SocialAccountStatus::Connected,
                'token_expires_at' => now()->addDays(47),
                'connected_at' => now()->subDays(118),
                'avatar' => '#DD2A7B',
            ],
            'linkedin_main' => [
                'platform' => Platform::LinkedIn,
                'external_account_id' => 'acme-studio-linkedin',
                'display_name' => 'Acme Studio',
                'status' => SocialAccountStatus::Connected,
                'token_expires_at' => now()->addDays(29),
                'connected_at' => now()->subDays(96),
                'avatar' => '#0A66C2',
            ],
            'tiktok_main' => [
                'platform' => Platform::Tiktok,
                'external_account_id' => 'acme-studio-tiktok',
                'display_name' => '@acme.studio',
                'status' => SocialAccountStatus::Connected,
                'token_expires_at' => now()->addDays(52),
                'connected_at' => now()->subDays(74),
                'avatar' => '#111111',
            ],
            'tiktok_expired' => [
                'platform' => Platform::Tiktok,
                'external_account_id' => 'acme-studio-tiktok-legacy',
                'display_name' => '@acme.studio (old account)',
                'status' => SocialAccountStatus::Expired,
                'token_expires_at' => now()->subDays(30),
                'connected_at' => now()->subDays(210),
                'avatar' => '#444444',
            ],
        ];

        $accounts = [];

        foreach ($definitions as $key => $definition) {
            $avatarPath = sprintf(
                '%s/demo/avatars/%s.jpg',
                trim((string) config('media.key_prefix'), '/'),
                $key,
            );

            $this->writePlaceholderImage($avatarPath, $definition['avatar'], 96, 96);

            $accounts[$key] = SocialAccount::updateOrCreate(
                [
                    'workspace_id' => $workspace->getKey(),
                    'platform' => $definition['platform'],
                    'external_account_id' => $definition['external_account_id'],
                ],
                [
                    'display_name' => $definition['display_name'],
                    'avatar_url' => Storage::disk(self::MEDIA_DISK)->url($avatarPath),
                    'access_token' => 'demo-access-token-'.$key,
                    'refresh_token' => 'demo-refresh-token-'.$key,
                    'token_expires_at' => $definition['token_expires_at'],
                    'status' => $definition['status'],
                    'connected_by_user_id' => $user->getKey(),
                    'connected_at' => $definition['connected_at'],
                ],
            );
        }

        return $accounts;
    }

    /**
     * @return array<string, Media>
     */
    private function createMedia(Workspace $workspace, User $user): array
    {
        $definitions = [
            'studio_lamp' => ['color' => '#4f46e5', 'width' => 1200, 'height' => 1200],
            'coffee_pour' => ['color' => '#0ea5e9', 'width' => 1200, 'height' => 1200],
            'desk_setup' => ['color' => '#059669', 'width' => 1200, 'height' => 1200],
            'packaging' => ['color' => '#d97706', 'width' => 1200, 'height' => 1200],
            'team_photo' => ['color' => '#e11d48', 'width' => 1200, 'height' => 800],
            'storefront' => ['color' => '#7c3aed', 'width' => 1200, 'height' => 800],
        ];

        $media = [];

        foreach ($definitions as $key => $definition) {
            $path = sprintf(
                '%s/demo/%s.jpg',
                trim((string) config('media.key_prefix'), '/'),
                $key,
            );

            $this->writePlaceholderImage($path, $definition['color'], $definition['width'], $definition['height']);

            $media[$key] = Media::withTrashed()->updateOrCreate(
                [
                    'workspace_id' => $workspace->getKey(),
                    'path' => $path,
                ],
                [
                    'uploaded_by_user_id' => $user->getKey(),
                    'disk' => self::MEDIA_DISK,
                    'type' => MediaType::Image,
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => (int) Storage::disk(self::MEDIA_DISK)->size($path),
                    'width' => $definition['width'],
                    'height' => $definition['height'],
                    'duration_seconds' => null,
                    'status' => MediaStatus::Ready,
                    'created_at' => now()->subDays(30),
                    'deleted_at' => null,
                ],
            );
        }

        $media['storefront_reel'] = Media::withTrashed()->updateOrCreate(
            [
                'workspace_id' => $workspace->getKey(),
                'path' => sprintf(
                    '%s/demo/storefront_reel.mp4',
                    trim((string) config('media.key_prefix'), '/'),
                ),
            ],
            [
                'uploaded_by_user_id' => $user->getKey(),
                'disk' => self::MEDIA_DISK,
                'type' => MediaType::Video,
                'mime_type' => 'video/mp4',
                'size_bytes' => 4_812_000,
                'width' => 1080,
                'height' => 1920,
                'duration_seconds' => 27,
                'status' => MediaStatus::Ready,
                'created_at' => now()->subDays(18),
                'deleted_at' => null,
            ],
        );

        return $media;
    }

    /**
     * Write a flat colour placeholder so demo thumbnails render without
     * shipping binary fixtures in the repository.
     */
    private function writePlaceholderImage(string $path, string $hex, int $width, int $height): void
    {
        $disk = Storage::disk(self::MEDIA_DISK);

        if ($disk->exists($path) || ! function_exists('imagecreatetruecolor')) {
            return;
        }

        [$red, $green, $blue] = array_map(
            'hexdec',
            str_split(ltrim($hex, '#'), 2),
        );

        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, $red, $green, $blue));
        imagefilledrectangle(
            $image,
            0,
            (int) ($height * 0.62),
            $width,
            $height,
            imagecolorallocate($image, (int) ($red * 0.6), (int) ($green * 0.6), (int) ($blue * 0.6)),
        );

        ob_start();
        imagejpeg($image, null, 82);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        $disk->put($path, $contents);
    }

    /**
     * @param  array<string, SocialAccount>  $accounts
     * @param  array<string, Media>  $media
     */
    private function createPosts(Workspace $workspace, User $user, array $accounts, array $media): void
    {
        $workspace->posts()->delete();

        foreach ($this->postDefinitions() as $definition) {
            $createdAt = $definition['created_at'];
            $scheduledAt = $definition['scheduled_at'] ?? null;

            $post = $workspace->posts()->forceCreate([
                'created_by_user_id' => $user->getKey(),
                'status' => $definition['status'],
                'scheduled_at' => $scheduledAt,
                'schedule_version' => 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            foreach ($definition['targets'] as $target) {
                $account = $accounts[$target['account']];

                PostTarget::create([
                    'post_id' => $post->getKey(),
                    'social_account_id' => $account->getKey(),
                    'caption' => $definition['caption'],
                    'title' => $definition['title'],
                    'status' => $target['status'],
                    'platform_post_id' => $target['status'] === PostTargetStatus::Published
                        ? $this->platformPostId($account->platform)
                        : null,
                    'published_at' => $target['published_at'] ?? null,
                    'error_message' => $target['error_message'] ?? null,
                    'retry_count' => $target['retry_count'] ?? 0,
                ]);
            }

            $post->media()->sync(
                collect($definition['media'] ?? [])
                    ->map(fn (string $key): array => [
                        'media_id' => $media[$key]->getKey(),
                        'position' => 0,
                    ])
                    ->all(),
            );
        }
    }

    private function platformPostId(Platform $platform): string
    {
        return $platform->value.fake()->unique()->numerify('##################');
    }

    /**
     * Demo posts spanning every status, spread over the previous, current and
     * next month so both the posts list and the calendar have content.
     *
     * @return list<array<string, mixed>>
     */
    private function postDefinitions(): array
    {
        return [
            [
                'caption' => 'Spring collection is live. Twelve pieces, one very patient photoshoot. #springdrop',
                'title' => null,
                'status' => PostStatus::Published,
                'created_at' => Carbon::now()->subDays(38)->setTime(9, 15),
                'media' => ['studio_lamp'],
                'targets' => [
                    ['account' => 'facebook_main', 'status' => PostTargetStatus::Published, 'published_at' => Carbon::now()->subDays(38)->setTime(9, 20)],
                    ['account' => 'instagram_main', 'status' => PostTargetStatus::Published, 'published_at' => Carbon::now()->subDays(38)->setTime(9, 21)],
                ],
            ],
            [
                'caption' => 'Retro sandal drop: two platforms live, one rejected — the team behind the shoot picked this one up by hand.',
                'title' => null,
                'status' => PostStatus::Failed,
                'created_at' => Carbon::now()->subDays(9)->setTime(10, 30),
                'media' => ['packaging'],
                'targets' => [
                    ['account' => 'facebook_main', 'status' => PostTargetStatus::Published, 'published_at' => Carbon::now()->subDays(9)->setTime(10, 35)],
                    ['account' => 'instagram_main', 'status' => PostTargetStatus::Published, 'published_at' => Carbon::now()->subDays(9)->setTime(10, 36)],
                    ['account' => 'linkedin_main', 'status' => PostTargetStatus::Failed, 'error_message' => 'The audience targeting tag is not permitted for this account.', 'published_at' => null],
                ],
            ],
            [
                'caption' => 'Three ways we brew the same single-origin roast. Number two is worth the mess.',
                'title' => null,
                'status' => PostStatus::Published,
                'created_at' => Carbon::now()->subDays(19)->setTime(14, 40),
                'media' => ['coffee_pour'],
                'targets' => [
                    ['account' => 'facebook_main', 'status' => PostTargetStatus::Published, 'published_at' => Carbon::now()->subDays(19)->setTime(14, 45)],
                    ['account' => 'linkedin_main', 'status' => PostTargetStatus::Published, 'published_at' => Carbon::now()->subDays(19)->setTime(14, 46)],
                ],
            ],
            [
                'caption' => 'Touring the new studio setup — everything on this wall is cable-managed, ask us how.',
                'title' => null,
                'status' => PostStatus::Published,
                'created_at' => Carbon::now()->subDays(11)->setTime(11, 5),
                'media' => ['desk_setup'],
                'targets' => [
                    ['account' => 'instagram_main', 'status' => PostTargetStatus::Published, 'published_at' => Carbon::now()->subDays(11)->setTime(11, 10)],
                    ['account' => 'linkedin_main', 'status' => PostTargetStatus::Published, 'published_at' => Carbon::now()->subDays(11)->setTime(11, 11)],
                ],
            ],
            [
                'caption' => 'POV: the packaging line at 6am. Forty seconds, no cuts, entirely caffeinated.',
                'title' => 'Packaging line at 6am',
                'status' => PostStatus::Published,
                'created_at' => Carbon::now()->subDays(6)->setTime(7, 30),
                'media' => ['packaging'],
                'targets' => [
                    ['account' => 'tiktok_main', 'status' => PostTargetStatus::Published, 'published_at' => Carbon::now()->subDays(6)->setTime(7, 35)],
                ],
            ],
            [
                'caption' => 'The team behind the shoot. Four people, one ring light, endless snacks.',
                'title' => null,
                'status' => PostStatus::Failed,
                'created_at' => Carbon::now()->subDays(3)->setTime(16, 0),
                'media' => ['team_photo'],
                'targets' => [
                    ['account' => 'facebook_main', 'status' => PostTargetStatus::Published, 'published_at' => Carbon::now()->subDays(3)->setTime(16, 5)],
                    ['account' => 'linkedin_main', 'status' => PostTargetStatus::Published, 'published_at' => Carbon::now()->subDays(3)->setTime(16, 6)],
                    ['account' => 'tiktok_main', 'status' => PostTargetStatus::Failed, 'error_message' => 'TikTok rejected the upload: file type not supported for this account.', 'retry_count' => 1],
                ],
            ],
            [
                'caption' => 'Storefront refresh is underway this week — same corner, brighter windows.',
                'title' => null,
                'status' => PostStatus::Publishing,
                'created_at' => Carbon::now()->subHour(2),
                'media' => ['storefront'],
                'targets' => [
                    ['account' => 'facebook_shop', 'status' => PostTargetStatus::Published, 'published_at' => Carbon::now()->subHour(2)],
                    ['account' => 'instagram_main', 'status' => PostTargetStatus::Pending],
                ],
            ],
            [
                'caption' => 'Draft: subscriber-only pricing experiment, needs a landing page before this goes out.',
                'title' => null,
                'status' => PostStatus::Draft,
                'created_at' => Carbon::now()->subDays(2)->setTime(18, 20),
                'targets' => [
                    ['account' => 'linkedin_main', 'status' => PostTargetStatus::Pending],
                ],
            ],
            [
                'caption' => 'Today only: free filter refills on every bag. No code, no app, no nonsense.',
                'title' => null,
                'status' => PostStatus::Scheduled,
                'created_at' => Carbon::now()->subDays(1)->setTime(12, 0),
                'scheduled_at' => Carbon::now()->addHours(4),
                'media' => ['coffee_pour'],
                'targets' => [
                    ['account' => 'facebook_main', 'status' => PostTargetStatus::Pending],
                    ['account' => 'instagram_main', 'status' => PostTargetStatus::Pending],
                ],
            ],
            [
                'caption' => 'New in: the travel grinder. Small enough for a carry-on, loud enough to wake the neighbours.',
                'title' => null,
                'status' => PostStatus::Scheduled,
                'created_at' => Carbon::now()->subDays(1)->setTime(12, 30),
                'scheduled_at' => Carbon::now()->addDays(2)->setTime(9, 0),
                'media' => ['desk_setup'],
                'targets' => [
                    ['account' => 'linkedin_main', 'status' => PostTargetStatus::Pending],
                ],
            ],
            [
                'caption' => 'Six months of the newsletter in under sixty seconds. Worth the scroll, we promise.',
                'title' => 'Six months in sixty seconds',
                'status' => PostStatus::Scheduled,
                'created_at' => Carbon::now()->subDay()->setTime(15, 45),
                'scheduled_at' => Carbon::now()->addDays(6)->setTime(18, 30),
                'media' => ['storefront_reel'],
                'targets' => [
                    ['account' => 'tiktok_main', 'status' => PostTargetStatus::Pending],
                    ['account' => 'instagram_main', 'status' => PostTargetStatus::Pending],
                ],
            ],
            [
                'caption' => 'Hiring: part-time roaster wanted. Details in the first comment.',
                'title' => null,
                'status' => PostStatus::Scheduled,
                'created_at' => Carbon::now()->subHours(10),
                'scheduled_at' => Carbon::now()->addDays(11)->setTime(10, 0),
                'targets' => [
                    ['account' => 'facebook_shop', 'status' => PostTargetStatus::Pending],
                    ['account' => 'linkedin_main', 'status' => PostTargetStatus::Pending],
                ],
            ],
            [
                'caption' => 'A quiet look at the roastery on a Sunday morning, before the queue starts.',
                'title' => 'Sunday morning at the roastery',
                'status' => PostStatus::Scheduled,
                'created_at' => Carbon::now()->subHours(6),
                'scheduled_at' => Carbon::now()->addDays(24)->setTime(8, 15),
                'media' => ['studio_lamp'],
                'targets' => [
                    ['account' => 'instagram_main', 'status' => PostTargetStatus::Pending],
                ],
            ],
            [
                'caption' => 'Anniversary sale — everything in the storefront window, twenty percent off.',
                'title' => null,
                'status' => PostStatus::Scheduled,
                'created_at' => Carbon::now()->subHours(3),
                'scheduled_at' => Carbon::now()->addDays(38)->setTime(9, 30),
                'media' => ['packaging'],
                'targets' => [
                    ['account' => 'facebook_main', 'status' => PostTargetStatus::Pending],
                    ['account' => 'facebook_shop', 'status' => PostTargetStatus::Pending],
                ],
            ],
        ];
    }

    /**
     * Lifetime metric totals each demo account reaches by the time a post is
     * 30 days old. Published targets borrow their social account's profile and
     * scale it by a deterministic per-target factor (see targetFactor), so posts
     * sharing an account still end up with distinct popularities.
     *
     * @return array<string, array<string, int>>
     */
    private function growthProfiles(): array
    {
        return [
            'acme-studio-facebook' => ['likes' => 1100, 'comments' => 190, 'shares' => 85, 'impressions' => 21000, 'reach' => 12400],
            'acme-coffee-facebook' => ['likes' => 460, 'comments' => 70, 'shares' => 35, 'impressions' => 9600, 'reach' => 5800],
            'acme-studio-instagram' => ['likes' => 1900, 'comments' => 240, 'shares' => 60, 'saves' => 420, 'impressions' => 24500, 'reach' => 16800],
            'acme-studio-linkedin' => ['likes' => 380, 'comments' => 55, 'shares' => 25, 'impressions' => 8900],
            'acme-studio-tiktok' => ['likes' => 3100, 'comments' => 620, 'shares' => 1100, 'views' => 52000],
        ];
    }

    /**
     * Fallback totals for a published account that has no explicit profile.
     *
     * @return array<string, int>
     */
    private function genericProfile(Platform $platform): array
    {
        return match ($platform) {
            Platform::Facebook => ['likes' => 600, 'comments' => 100, 'shares' => 40, 'impressions' => 12000, 'reach' => 7000],
            Platform::Instagram => ['likes' => 900, 'comments' => 120, 'shares' => 40, 'saves' => 200, 'impressions' => 14000, 'reach' => 9500],
            Platform::LinkedIn => ['likes' => 200, 'comments' => 30, 'shares' => 15, 'impressions' => 5000],
            Platform::Tiktok => ['likes' => 1500, 'comments' => 300, 'shares' => 500, 'views' => 25000],
        };
    }

    /**
     * The metric keys a platform's fetch action actually reports. Everything
     * else stays null in the snapshot, matching the real API responses: TikTok
     * reports views (no reach), LinkedIn reports no reach and carries a
     * pre-computed engagements total.
     *
     * @return list<string>
     */
    private function platformMetricKeys(Platform $platform): array
    {
        return match ($platform) {
            Platform::Facebook => ['likes', 'comments', 'shares', 'impressions', 'reach'],
            Platform::Instagram => ['likes', 'comments', 'shares', 'saves', 'impressions', 'reach'],
            Platform::LinkedIn => ['likes', 'comments', 'shares', 'impressions'],
            Platform::Tiktok => ['likes', 'comments', 'shares', 'views'],
        };
    }

    /**
     * A deterministic [0.65, 1.45) popularity factor derived from the target id,
     * so re-seeding keeps stable values while posts on the same account differ.
     */
    private function targetFactor(PostTarget $target): float
    {
        $roll = crc32($target->getKey()) % 1000;

        return 0.65 + ($roll / 1000) * 0.8;
    }

    /**
     * Backfill daily cumulative snapshots for every published target.
     *
     * One snapshot per target per day, starting on the day the post went live
     * (capped to the analytics' 30-day window) and growing along a sqrt curve
     * toward the account's lifetime totals — steep early engagement that
     * tapers off, the way real posts behave.
     */
    private function createMetrics(Workspace $workspace, User $user): void
    {
        $targets = PostTarget::query()
            ->where('status', PostTargetStatus::Published)
            ->whereNotNull('platform_post_id')
            ->whereIn('post_id', $workspace->posts()->pluck('id'))
            ->with('socialAccount')
            ->get();

        $since = today()->subDays(self::METRICS_WINDOW_DAYS - 1);
        $todayDate = today()->copy()->startOfDay();
        $profiles = $this->growthProfiles();

        $count = 0;
        $targetCount = 0;

        foreach ($targets as $target) {
            $platform = $target->socialAccount->platform;
            $profile = $profiles[$target->socialAccount->external_account_id] ?? $this->genericProfile($platform);
            $factor = $this->targetFactor($target);

            $started = Carbon::instance($target->published_at ?? $target->created_at)
                ->startOfDay();

            if ($started->greaterThan($todayDate)) {
                continue;
            }

            $targetCount++;

            $windowStart = $started->greaterThan($since) ? $started : $since->copy();

            $dayCount = (int) round(($todayDate->getTimestamp() - $windowStart->getTimestamp()) / 86400) + 1;
            $totalDays = (int) round(($todayDate->getTimestamp() - $started->getTimestamp()) / 86400) + 1;

            for ($offset = 0; $offset < $dayCount; $offset++) {
                $date = $windowStart->copy()->startOfDay()->addDays($offset);
                $ageDays = (int) round(($date->getTimestamp() - $started->getTimestamp()) / 86400) + 1;
                $curve = sqrt($ageDays / $totalDays);

                $data = [
                    'likes' => null,
                    'comments' => null,
                    'shares' => null,
                    'saves' => null,
                    'impressions' => null,
                    'reach' => null,
                    'engagements' => null,
                    'views' => null,
                ];

                foreach ($this->platformMetricKeys($platform) as $key) {
                    $data[$key] = (int) round($profile[$key] * $factor * $curve);
                }

                if ($platform === Platform::LinkedIn) {
                    $data['engagements'] = $data['likes'] + $data['comments'] + $data['shares'];
                }

                PostMetric::updateOrCreate(
                    [
                        'post_target_id' => $target->getKey(),
                        'snapshot_date' => $date->toDateString(),
                    ],
                    [
                        'post_id' => $target->post_id,
                        'platform' => $platform->value,
                        'snapshot_type' => 'snapshot',
                        'data' => $data,
                    ],
                );

                $count++;
            }
        }

        $this->command?->info(sprintf('[metrics] backfilled %d daily snapshots across %d published targets', $count, $targetCount));
    }

    /**
     * Give the demo user one unread failure notification so the bell badge and
     * the dashboard's unread-count KPI are non-zero. Prior demo notifications
     * are removed first so re-seeding does not pile up duplicates.
     */
    private function seedFailureNotification(Workspace $workspace, User $user): void
    {
        $user->notifications()->where('type', PostTargetFailedNotification::class)->delete();

        $failedTarget = PostTarget::query()
            ->whereIn('post_id', $workspace->posts()->pluck('id'))
            ->where('status', PostTargetStatus::Failed)
            ->first();

        if ($failedTarget !== null) {
            Notification::sendNow($user, new PostTargetFailedNotification($failedTarget));
        }
    }

    /**
     * Give the demo workspace's first retryable failure a retry history so the
     * post page shows the retry affordance in its non-default states: a live
     * cooldown countdown, a remaining retry, and the "last retried" line.
     *
     * The history is derived from the live `retry.*` settings rather than
     * hard-coded, because the page reads the same settings to decide what to
     * render. Fixed numbers would quietly contradict a tuned policy — seed two
     * attempts against a cap of one and the demo shows "exhausted" while the
     * docs promise a countdown. A cap of one cannot show both a countdown and a
     * remaining retry, so the demo settles for the exhausted state, which is
     * correct rather than broken.
     */
    private function seedRetryAttempts(Workspace $workspace, User $user): void
    {
        $post = Post::query()
            ->whereIn('id', $workspace->posts()->pluck('id'))
            ->whereHas(
                'targets',
                fn ($query) => $query
                    ->where('status', PostTargetStatus::Failed)
                    ->whereNull('platform_post_id')
                    ->whereNull('platform_upload_id'),
            )
            ->first();

        if ($post === null) {
            return;
        }

        $settings = app(Settings::class);

        // Two attempts leaves the default cap of three with one retry in hand.
        $attemptCount = max(1, min(2, $settings->int('retry.max_retries', 3) - 1));

        // Just inside the cooldown, so the button counts down and then frees up.
        $newestSecondsAgo = max(1, min(240, $settings->int('retry.cooldown_seconds', 300) - 5));

        $offsets = [$newestSecondsAgo];

        if ($attemptCount > 1) {
            $offsets[] = $newestSecondsAgo * 4;
        }

        foreach ($offsets as $secondsAgo) {
            $post->retryAttempts()->create([
                'attempted_by_user_id' => $user->getKey(),
                'attempted_legs' => 1,
                'attempted_at' => now()->subSeconds($secondsAgo),
            ]);
        }
    }
}
