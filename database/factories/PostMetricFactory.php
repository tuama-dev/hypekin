<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\PostMetric;
use App\Models\PostTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostMetric>
 */
class PostMetricFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The snapshot belongs to a single post target, so post_id and platform
     * derive from the created target rather than being guessed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_target_id' => static fn (): string => PostTarget::factory()->create()->getKey(),
            'post_id' => static fn (array $attributes): string => PostTarget::query()
                ->findOrFail($attributes['post_target_id'])->post_id,
            'platform' => static fn (array $attributes): Platform => PostTarget::query()
                ->findOrFail($attributes['post_target_id'])->socialAccount->platform,
            'snapshot_type' => 'snapshot',
            'snapshot_date' => now(),
            'data' => [
                'likes' => fake()->numberBetween(0, 500),
                'comments' => fake()->numberBetween(0, 100),
                'shares' => fake()->numberBetween(0, 50),
                'saves' => null,
                'impressions' => fake()->numberBetween(100, 5000),
                'reach' => fake()->numberBetween(50, 3000),
                'engagements' => null,
                'views' => null,
            ],
        ];
    }
}
