<?php

namespace Database\Factories;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
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
            'uploaded_by_user_id' => User::factory(),
            'disk' => config('media.disk', 's3'),
            'path' => 'posts/'.Str::ulid().'.jpg',
            'type' => MediaType::Image,
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(10_000, 500_000),
            'width' => 1080,
            'height' => 1080,
            'duration_seconds' => null,
            'status' => MediaStatus::Ready,
        ];
    }
}
