<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\PostRetryAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostRetryAttempt>
 */
class PostRetryAttemptFactory extends Factory
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
            'attempted_by_user_id' => User::factory(),
            'attempted_legs' => 1,
            'attempted_at' => now(),
        ];
    }

    /**
     * Indicate the attempt happened a number of seconds ago, for cooldown
     * windows that should still be open.
     */
    public function secondsAgo(int $seconds): static
    {
        return $this->state(fn (array $attributes): array => [
            'attempted_at' => now()->subSeconds($seconds),
        ]);
    }
}
