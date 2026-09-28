<?php

namespace App\Http\Controllers\Application;

use App\Http\Controllers\Application\Concerns\FilterableAccounts;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Workspace;
use Carbon\CarbonImmutable as Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    use FilterableAccounts;

    private const WINDOW_MONTHS = 6;

    /**
     * Render the publishing calendar.
     *
     * The client keeps one month at a time and pans back/forward instantly
     * within a +/-6 month window around the requested month; navigating
     * beyond a window edge refetches this action with the next month.
     * Scheduled posts sit on their scheduled_at date, all other posts on
     * their created_at date.
     */
    public function index(Request $request, Workspace $workspace): Response
    {
        $validated = $request->validate([
            'account' => ['nullable', 'string', 'max:40'],
        ]);

        $monthRaw = $request->query('month');
        $month = is_string($monthRaw) && preg_match('/^\d{4}-\d{2}$/', $monthRaw)
            ? Carbon::parse($monthRaw.'-01')
            : Carbon::now()->startOfMonth();

        $accountId = $validated['account'] ?? null;
        $windowStart = $month->subMonths(self::WINDOW_MONTHS)->startOfMonth();
        $windowEnd = $month->addMonths(self::WINDOW_MONTHS)->endOfMonth();

        $posts = $workspace->posts()
            ->when(
                $accountId !== null,
                fn ($query) => $query->whereHas(
                    'targets',
                    fn ($targetQuery) => $targetQuery->where(
                        'social_account_id',
                        $accountId,
                    ),
                ),
            )
            ->with(['targets.socialAccount', 'media'])
            ->where(function ($query) use ($windowStart, $windowEnd): void {
                $query->whereNotNull('scheduled_at')
                    ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
                    ->orWhereNull('scheduled_at')
                    ->whereBetween('created_at', [$windowStart, $windowEnd]);
            })
            ->orderByDesc('scheduled_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Post $post): array => [
                'id' => $post->getKey(),
                'status' => [
                    'value' => $post->status->value,
                    'label' => $post->status->label(),
                ],
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'created_at' => $post->created_at?->toIso8601String(),
                'caption' => $post->targets->first()?->caption ?? '',
                'targets' => $post->targets
                    ->map(fn (PostTarget $target): array => [
                        'id' => $target->getKey(),
                        'platform' => [
                            'value' => $target->socialAccount->platform->value,
                            'label' => $target->socialAccount->platform->label(),
                        ],
                        'display_name' => $target->socialAccount->display_name,
                        'status' => [
                            'value' => $target->status->value,
                            'label' => $target->status->label(),
                        ],
                    ])
                    ->values(),
                'media' => $post->media
                    ->map(fn (Media $media): array => [
                        'id' => $media->getKey(),
                        'url' => $media->publicUrl(),
                    ])
                    ->values(),
            ])
            ->values();

        return Inertia::render('Application/Posts/Calendar', [
            'month' => $month->format('Y-m'),
            'posts' => $posts,
            'accounts' => $this->filterableAccounts($workspace),
            'filters' => [
                'account' => $accountId,
            ],
        ]);
    }
}
