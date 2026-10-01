<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[Temperature(0.9)]
#[MaxTokens(600)]
#[Timeout(45)]
class CaptionGenerator implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Per-platform hashtag count guidance.
     *
     * @var array<string, string>
     */
    private const PLATFORM_RULES = [
        'instagram' => 'exactly 10 hashtags',
        'tiktok' => '5 to 8 hashtags',
        'facebook' => '3 to 5 hashtags',
        'linkedin' => '2 to 3 hashtags',
    ];

    public function __construct(
        private ?string $tone,
        private array $platforms,
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $tone = $this->tone ?? 'engaging';

        $platformSection = collect($this->platforms)
            ->map(fn (string $platform) => sprintf(
                '- %s: %s, formatted as plain #hashtag words (lowercase or title case, no punctuation or spaces between them)',
                $platform,
                self::PLATFORM_RULES[$platform] ?? 'a suitable number of hashtags',
            ))
            ->implode(PHP_EOL);

        $tiktokTitle = in_array('tiktok', $this->platforms, true)
            ? PHP_EOL.'A "title" of 22 characters or fewer for TikTok must be provided when TikTok is a target platform.'
            : PHP_EOL.'When TikTok is not a target platform, return null for "title".';

        return <<<EOT
            You are a professional social media copywriter. Write ONE engaging social media post
            for the given brief in a {$tone} tone. The same caption will be published across all
            the selected platforms, so keep it universally appropriate and free of emoji that
            render poorly on any platform. Do not include hashtags or "#" symbols inside the
            caption body itself.

            Hashtag rules per selected platform:
            {$platformSection}

            {$tiktokTitle}

            Return the caption, the optional TikTok title, and a hashtags map keyed by exactly
            the platform names provided. Each entry is a list of "#tag" strings (with "#") ready
            to append at the end of the caption for that platform.
            EOT;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'caption' => $schema->string()->required(),
            'title' => $schema->string()->nullable(),
            'hashtags' => $schema->object(fn (JsonSchema $schema) => collect($this->platforms)
                ->mapWithKeys(fn (string $platform) => [$platform => $schema->array()->items($schema->string())])
                ->all())->required(),
        ];
    }
}
