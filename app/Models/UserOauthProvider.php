<?php

namespace App\Models;

use Database\Factories\UserOauthProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider_name
 * @property string $provider_id
 * @property string|null $token
 * @property string|null $refresh_token
 * @property string|null $nickname
 * @property string|null $avatar
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserOauthProvider extends Model
{
    /** @use HasFactory<UserOauthProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider_name',
        'provider_id',
        'token',
        'refresh_token',
        'nickname',
        'avatar',
        'token_expires_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
        ];
    }
}
