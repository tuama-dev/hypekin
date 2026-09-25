<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'platform' => Platform::LinkedIn,
            'external_account_id' => fake()->uuid(),
            'display_name' => fake()->name(),
            'access_token' => fake()->uuid(),
            'refresh_token' => fake()->uuid(),
            'token_expires_at' => now()->addDays(60),
            'status' => SocialAccountStatus::Connected,
            'connected_by_user_id' => User::factory(),
            'connected_at' => now(),
        ];
    }
}
