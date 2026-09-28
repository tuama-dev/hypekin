<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\SocialAccountStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property Platform $platform
 * @property string $external_account_id
 * @property string $display_name
 * @property string|null $avatar_url
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property SocialAccountStatus $status
 * @property string|null $connected_by_user_id
 * @property Carbon|null $connected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'workspace_id',
    'platform',
    'external_account_id',
    'display_name',
    'avatar_url',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'status',
    'connected_by_user_id',
    'connected_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class SocialAccount extends Model
{
    use HasFactory, HasUlids;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    /**
     * The status the UI should surface for this account.
     *
     * Expired is a runtime signal, never stored: a connected account whose
     * token has lapsed is shown as expired, while explicit states such as
     * Revoked always win and are never downgraded.
     */
    public function effectiveStatus(): SocialAccountStatus
    {
        if ($this->status === SocialAccountStatus::Connected
            && $this->token_expires_at !== null
            && $this->token_expires_at->isPast()) {
            return SocialAccountStatus::Expired;
        }

        return $this->status;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => SocialAccountStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }
}
