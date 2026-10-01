<?php

namespace App\Http\Controllers\Application;

use App\Ai\Agents\CaptionGenerator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\AiCaptionRequest;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class AiCaptionController extends Controller
{
    /**
     * Generate a caption, optional TikTok title, and per-platform hashtags
     * from a short brief using the configured AI provider.
     *
     * No data is persisted by this endpoint; the composer edits the result
     * before the captions are copied onto the post's targets.
     */
    public function generate(AiCaptionRequest $request, Workspace $workspace): JsonResponse
    {
        if ($this->providerKey() === null) {
            return response()->json(['error' => "AI captioning isn't configured."], 422);
        }

        try {
            $response = (new CaptionGenerator(
                $request->input('tone'),
                $request->platforms(),
            ))->prompt($request->string('brief'));
        } catch (RateLimitedException|ProviderOverloadedException) {
            return response()->json(['error' => 'The AI provider is busy. Please try again.'], 503);
        } catch (Throwable) {
            return response()->json(['error' => 'Could not generate the caption. Please try again.'], 503);
        }

        return response()->json([
            'caption' => $response['caption'],
            'title' => $response['title'] ?? null,
            'hashtags' => $response['hashtags'],
        ]);
    }

    /**
     * The API key of the configured default provider, if one is set.
     */
    private function providerKey(): ?string
    {
        $provider = config('ai.default');

        return filled(config("ai.providers.$provider.key"))
            ? (string) config("ai.providers.$provider.key")
            : null;
    }
}
