<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
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
            'created_by_user_id' => User::factory(),
            'status' => PostStatus::Published,
            'scheduled_at' => null,
        ];
    }

    /**
     * Indicate the post is scheduled for a future time.
     */
    public function scheduled(?string $at = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Scheduled,
            'scheduled_at' => $at ?? now()->addHour(),
        ]);
    }

    /**
     * Indicate the post failed to publish.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Failed,
        ]);
    }
}
