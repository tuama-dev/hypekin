<?php

/**
 * Verifies the token migration against pre-existing plaintext rows: it must
 * widen the columns, encrypt what was there, decrypt back to the original
 * value, survive a re-run, and restore plaintext on rollback.
 *
 * The narrow string(255) columns were not silently truncating under MySQL
 * strict mode — they rejected the insert outright, so any provider issuing a
 * token longer than 255 characters could not be connected at all. Widening to
 * text is what fixes that.
 */

use App\Models\User;
use App\Models\UserOauthProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('encrypts pre-existing plaintext tokens and is safe to re-run', function () {
    $table = 'user_oauth_providers';

    $user = User::factory()->create();
    $short = 'short-token';
    $long = str_repeat('long-token-', 80);

    expect(strlen($long))->toBeGreaterThan(255);

    // Re-running the migration must be a no-op on already-encrypted values.
    DB::table($table)->insert([
        'id' => (string) Str::ulid(),
        'user_id' => $user->getKey(),
        'provider_name' => 'legacy-short',
        'provider_id' => 'legacy-1',
        'token' => Crypt::encryptString($short),
        'refresh_token' => Crypt::encryptString($long),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertSuccessful();

    // After the rollback the values are readable plaintext again. The columns
    // stay wide on purpose, because narrowing would truncate the long token.
    $plain = DB::table($table)->where('provider_id', 'legacy-1')->first();

    expect($plain->token)->toBe($short)
        ->and($plain->refresh_token)->toBe($long);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $raw = DB::table($table)->where('provider_id', 'legacy-1')->first();

    expect($raw->token)->not->toBe($short)
        ->and($raw->refresh_token)->not->toBe($long);

    $model = UserOauthProvider::query()->where('provider_id', 'legacy-1')->firstOrFail();

    expect($model->token)->toBe($short)
        ->and($model->refresh_token)->toBe($long);
});

it('stores a token longer than the old column width', function () {
    $user = User::factory()->create();
    $long = str_repeat('a', 900);

    $provider = $user->oauthProviders()->create([
        'provider_name' => 'google',
        'provider_id' => 'google-long-token',
        'token' => $long,
        'refresh_token' => $long,
    ]);

    expect($provider->fresh()->token)->toBe($long)
        ->and($provider->fresh()->refresh_token)->toBe($long);

    expect(Schema::getColumnType($provider->getTable(), 'token'))->toBe('text');
});

it('round-trips a token through the encrypted cast', function () {
    $user = User::factory()->create();

    $provider = $user->oauthProviders()->create([
        'provider_name' => 'google',
        'provider_id' => 'google-round-trip',
        'token' => 'plain-token-value',
    ]);

    $stored = DB::table('user_oauth_providers')->where('id', $provider->getKey())->first();

    expect($stored->token)->not->toBe('plain-token-value')
        ->and($provider->fresh()->token)->toBe('plain-token-value');
});
