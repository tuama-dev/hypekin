<?php

namespace App\Settings;

use App\Models\Setting;

/**
 * Read/write access to the runtime-tunable business policy in the settings
 * table (retry caps, TikTok poll budget, verification cooldown).
 *
 * Infrastructure values — endpoints, HTTP timeouts, pagination, auth throttle,
 * storage TTL — deliberately stay in code and config; only business policy
 * that operators may need to tune without a deploy belongs here.
 *
 * Registered as a container *scoped* binding, so each HTTP request and each
 * queue job gets its own instance. That matters more than it looks: policy is
 * read from inside publish jobs, and a shared cache meant a setting changed in
 * one process could not be seen by another for up to an hour — a worker would
 * keep publishing under a policy that no longer existed. The container discards
 * the instance between lifecycles, so the window for a stale read is now one
 * request or one job rather than an hour across every worker.
 *
 * Within a lifecycle the whole table is read once and memoized, so a page render
 * or a job run still costs a single query no matter how many settings it reads.
 * Every write the application makes goes through Setting's model events, which
 * clear the memo, so a `Settings::set()` or a direct `Setting::create()` is
 * visible to the next read in the same request.
 *
 * Defaults are supplied by the caller (`$settings->int('retry.max_retries', 3)`),
 * so the value each call site falls back to is visible where it is used and an
 * unseeded database still behaves correctly.
 */
class Settings
{
    /**
     * The whole settings table as a key => value map, or null until first read.
     *
     * @var array<string, mixed>|null
     */
    private ?array $map = null;

    /**
     * Read a setting as an int, falling back to the caller's default when the
     * key is unset or holds a non-numeric value.
     */
    public function int(string $key, int $default): int
    {
        $value = $this->all()[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Read a setting as a string, falling back to the caller's default when the
     * key is unset or holds a non-scalar value.
     */
    public function string(string $key, string $default): string
    {
        $value = $this->all()[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Read a setting as a bool, falling back to the caller's default when the
     * key is unset or holds a non-boolean value.
     */
    public function bool(string $key, bool $default): bool
    {
        $value = $this->all()[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * Write a setting, creating the row when the key is new.
     *
     * Goes through the model so the write events fire and drop the memo, making
     * the new value visible to every later read in this lifecycle.
     */
    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Drop the memoized map. Called by the Setting model on every write.
     */
    public function forget(): void
    {
        $this->map = null;
    }

    /**
     * Every stored setting as a key => value map, read once per lifecycle.
     *
     * @return array<string, mixed>
     */
    private function all(): array
    {
        return $this->map ??= Setting::query()->pluck('value', 'key')->all();
    }
}
