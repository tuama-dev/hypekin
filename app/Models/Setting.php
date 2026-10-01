<?php

namespace App\Models;

use App\Settings\Settings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single runtime-tunable business policy value, keyed by a dotted name such
 * as `retry.max_retries`.
 *
 * Rows are seeded, not created through the application, but writes are allowed
 * so a future settings page (or tinker) can change policy without a deploy.
 * Every write drops the live Settings instance's memo, so a change is visible to
 * the rest of the request or job that made it rather than only the next one.
 *
 * A raw `Setting::query()->update(...)` bypasses these events, and the memo will
 * not notice until the current lifecycle ends. Route writes through
 * Settings::set() so that does not happen.
 *
 * @property string $id
 * @property string $key
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use HasUlids;

    /**
     * Drop the settings memo whenever the table changes underneath it.
     */
    protected static function booted(): void
    {
        static::saved(fn () => app(Settings::class)->forget());
        static::deleted(fn () => app(Settings::class)->forget());
    }

    /**
     * Get the attributes that should be cast.
     *
     * JSON scalars (int, float, bool, string) and arrays are all valid setting
     * values, so this cast is a JSON decode rather than an array cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
