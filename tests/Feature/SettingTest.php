<?php

use App\Models\Setting;
use App\Settings\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Store a setting the way the seeder and a future settings page would.
 */
function storeSetting(string $key, mixed $value): Setting
{
    return Setting::query()->create(['key' => $key, 'value' => $value]);
}

/**
 * The scoped Settings instance for the current lifecycle, which is how
 * application code resolves it.
 */
function settings(): Settings
{
    return app(Settings::class);
}

test('a stored setting is returned by its typed getter', function () {
    storeSetting('retry.max_retries', 5);

    expect(settings()->int('retry.max_retries', 3))->toBe(5);
});

test('an unset key falls back to the caller default', function () {
    expect(settings()->int('retry.max_retries', 3))->toBe(3)
        ->and(settings()->string('publish.tiktok_api_base', 'default'))->toBe('default')
        ->and(settings()->bool('publish.background', true))->toBeTrue();
});

test('a stored value of the wrong type falls back to the caller default', function () {
    storeSetting('retry.max_retries', 'not-a-number');
    storeSetting('publish.background', 'yes');

    expect(settings()->int('retry.max_retries', 3))->toBe(3)
        ->and(settings()->bool('publish.background', false))->toBeFalse();
});

test('string and bool getters read their stored values', function () {
    storeSetting('publish.mode', 'queue');
    storeSetting('publish.background', false);

    expect(settings()->string('publish.mode', 'sync'))->toBe('queue')
        ->and(settings()->bool('publish.background', true))->toBeFalse();
});

test('repeated reads in one lifecycle cost a single query', function () {
    storeSetting('retry.max_retries', 5);

    DB::flushQueryLog();
    DB::enableQueryLog();

    settings()->int('retry.max_retries', 3);
    settings()->int('retry.cooldown_seconds', 300);

    $selects = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_starts_with($query['query'], 'select'))
        ->count();

    expect($selects)->toBe(1);
});

test('writing a setting through the accessor is visible to the next read', function () {
    expect(settings()->int('retry.max_retries', 3))->toBe(3);

    settings()->set('retry.max_retries', 7);

    expect(settings()->int('retry.max_retries', 3))->toBe(7)
        ->and(Setting::sole()->value)->toBe(7);
});

test('a direct model write is visible to the next read in the same lifecycle', function () {
    expect(settings()->int('retry.max_retries', 3))->toBe(3);

    storeSetting('retry.max_retries', 9);

    expect(settings()->int('retry.max_retries', 3))->toBe(9);
});

test('deleting a setting restores the default immediately', function () {
    storeSetting('retry.max_retries', 9);

    expect(settings()->int('retry.max_retries', 3))->toBe(9);

    Setting::sole()->delete();

    expect(settings()->int('retry.max_retries', 3))->toBe(3);
});

test('a new lifecycle sees a change made after the previous one read', function () {
    storeSetting('retry.max_retries', 5);

    expect(settings()->int('retry.max_retries', 3))->toBe(5);

    // A raw bulk update: no model events, and no casts, so this is exactly the
    // change the previous design could not see for an hour.
    DB::table('settings')->where('key', 'retry.max_retries')->update([
        'value' => json_encode(9),
        'updated_at' => now(),
    ]);

    // What the queue worker does between jobs: discard the scoped instances.
    // Illuminate\Queue\QueueServiceProvider hands this to Worker as $resetScope,
    // which it runs after every job it processes.
    app()->forgetScopedInstances();

    expect(app(Settings::class)->int('retry.max_retries', 3))->toBe(9);
});

test('the scoped instance is shared within one lifecycle', function () {
    expect(app(Settings::class))->toBe(app(Settings::class));
});

test('the seeded policy keys match the call-site defaults', function () {
    $this->seed(SettingsSeeder::class);

    expect(settings()->int('retry.max_retries', 3))->toBe(3)
        ->and(settings()->int('retry.cooldown_seconds', 300))->toBe(300)
        ->and(settings()->int('publish.tiktok_max_polls', 10))->toBe(10)
        ->and(settings()->int('publish.tiktok_poll_delay_seconds', 60))->toBe(60)
        ->and(settings()->int('verification.resend_cooldown', 60))->toBe(60);
});

test('re-seeding settings leaves tuned values alone', function () {
    settings()->set('retry.max_retries', 1);

    $this->seed(SettingsSeeder::class);

    expect(settings()->int('retry.max_retries', 3))->toBe(1)
        ->and(Setting::count())->toBe(5);
});
