<?php

namespace Database\Factories;

use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostTarget>
 */
class PostTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'social_account_id' => SocialAccount::factory(),
            'caption' => fake()->sentence(),
            'status' => PostTargetStatus::Published,
            'platform_post_id' => fake()->numerify('############'),
            'published_at' => now(),
            'error_message' => null,
            'retry_count' => 0,
        ];
    }

    /**
     * Indicate the target is still pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostTargetStatus::Pending,
            'platform_post_id' => null,
            'published_at' => null,
        ]);
    }

    /**
     * Indicate the target failed to publish.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostTargetStatus::Failed,
            'platform_post_id' => null,
            'published_at' => null,
            'error_message' => 'Platform rejected the request.',
        ]);
    }
}
