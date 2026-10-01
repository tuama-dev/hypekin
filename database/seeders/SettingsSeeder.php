<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seed the runtime-tunable business policy the application reads through
 * App\Settings\Settings: the user retry cap/cooldown, the TikTok status poll
 * budget/interval and the email verification resend cooldown.
 *
 * Only missing keys are inserted, so re-seeding a database that has been tuned
 * leaves existing values alone. Each key also carries a call-site default, so
 * an unseeded database behaves identically to a seeded one.
 */
class SettingsSeeder extends Seeder
{
    /**
     * @var array<string, bool|int|string>
     */
    private const DEFAULTS = [
        'retry.max_retries' => 3,
        'retry.cooldown_seconds' => 300,
        'publish.tiktok_max_polls' => 10,
        'publish.tiktok_poll_delay_seconds' => 60,
        'verification.resend_cooldown' => 60,
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->command?->info('Settings seeded. '.count(self::DEFAULTS).' policy keys available.');
    }
}
