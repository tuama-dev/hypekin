import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import {
    AlertCircle,
    ArrowLeft,
    CalendarClock,
    CheckCircle2,
    Clock,
    FileText,
    RefreshCcw,
} from 'lucide-react';
import AuthenticatedLayout from '@/components/Layout/AuthenticatedLayout';
import PostController from '@/actions/App/Http/Controllers/Application/PostController';
import { MetricSparkline } from '@/components/Posts/MetricSparkline';
import {
    FALLBACK_STATUS_CHIP_CLASS,
    postStatusChipClasses,
    type PostStatusValue,
} from '@/components/ui/postStatus';
import {
    platformBrands,
    type PlatformValue,
} from '@/components/ui/platformBrands';
import { cn } from '@/lib/utils';

interface PostMediaView {
    id: string;
    url: string;
}

interface PostMetricSnapshot {
    id: string;
    snapshot_date: string;
    data: Record<string, number | null>;
}

interface PostTargetView {
    id: string;
    platform: { value: string; label: string };
    display_name: string;
    status: { value: string; label: string };
    error_message: string | null;
    metrics: PostMetricSnapshot[];
}

/**
 * The retry policy, derived server-side from the post's retry audit log and the
 * `retry.*` settings. The endpoint re-checks it, so these fields only drive how
 * the button looks.
 */
interface PostRetryView {
    eligible_legs: number;
    /**
     * Targets that have failed at all, whether or not they can be re-sent. Lets
     * the page explain a dead end instead of silently showing nothing.
     */
    failed_legs: number;
    retries_left: number;
    exhausted: boolean;
    last_retried_at: string | null;
    /**
     * When a retry next becomes allowed, or null if the post was never retried.
     * An instant rather than a remaining count, so the countdown does not
     * inherit any disagreement between this machine's clock and the server's.
     */
    retry_available_at: string | null;
}

interface PostView {
    id: string;
    status: { value: string; label: string };
    scheduled_at: string | null;
    created_at: string | null;
    caption: string;
    title: string | null;
    targets: PostTargetView[];
    media: PostMediaView[];
    retry: PostRetryView;
}

interface PostsShowPageProps {
    post: PostView;
}

function formatDate(value: string | null): string {
    if (value === null) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function formatCountdown(totalSeconds: number): string {
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    // Grows to hours past an hour, matching PostController::formatWait on the
    // server: a "3:00" cooldown would read as three minutes.
    return minutes >= 60
        ? `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
        : `${minutes}:${String(seconds).padStart(2, '0')}`;
}

const METRIC_LABELS: Record<string, string> = {
    likes: 'Likes',
    comments: 'Comments',
    shares: 'Shares',
    saves: 'Saves',
    impressions: 'Impressions',
    reach: 'Reach',
    engagements: 'Engagements',
    views: 'Views',
};

export default function PostsShow({ post }: PostsShowPageProps) {
    const { auth } = usePage().props;
    const workspace = auth.workspace;
    const canPublish = workspace?.abilities.includes('publish') ?? false;

    const {
        eligible_legs: eligibleLegs,
        failed_legs: failedLegs,
        retries_left: retriesLeft,
        exhausted,
        last_retried_at: lastRetriedAt,
        retry_available_at: retryAvailableAt,
    } = post.retry;

    // Memoised on the serialised instant: a bare `new Date(...)` would be a new
    // object on every render and tear the interval below down and back up each
    // time, restarting the countdown.
    const retryDeadline = useMemo(
        () =>
            retryAvailableAt === null
                ? null
                : new Date(retryAvailableAt).getTime(),
        [retryAvailableAt],
    );

    const [now, setNow] = useState(() => Date.now());
    const retryForm = useForm({});

    useEffect(() => {
        if (retryDeadline === null) {
            return;
        }

        let id: ReturnType<typeof setInterval>;

        const tick = () => {
            if (Date.now() >= retryDeadline) {
                setNow(retryDeadline);
                clearInterval(id);
            } else {
                setNow(Date.now());
            }
        };

        id = setInterval(tick, 1000);
        tick();

        return () => clearInterval(id);
    }, [retryDeadline]);

    const remainingSeconds =
        retryDeadline === null
            ? 0
            : Math.max(0, Math.ceil((retryDeadline - now) / 1000));

    const coolingDown = remainingSeconds > 0;
    const canRetry = eligibleLegs > 0 && !exhausted && !coolingDown;
    const retrying = retryForm.processing;

    if (workspace === null) {
        return null;
    }

    const workspaceSlug = workspace.slug;

    function handleRetry() {
        if (!canRetry) {
            return;
        }

        retryForm.post(
            PostController.retry({
                workspace: workspaceSlug,
                post: post.id,
            }).url,
            { preserveScroll: true },
        );
    }

    return (
        <AuthenticatedLayout>
            <Head title={post.title || post.caption || 'Post Details'} />

            <div className="flex w-full flex-col px-4 py-8 sm:px-6">
                <div className="mb-6 flex items-center justify-between gap-4">
                    <Link
                        href={
                            PostController.index({ workspace: workspaceSlug })
                                .url
                        }
                        className="inline-flex items-center gap-2 text-xs font-semibold text-(--muted) transition hover:text-(--text)"
                    >
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        Back to posts
                    </Link>

                    {failedLegs > 0 && eligibleLegs === 0 && (
                        <p className="max-w-xs text-right text-xs text-(--muted)">
                            Nothing left to retry — every failed target was
                            already submitted to its platform, so resending
                            could publish it twice.
                        </p>
                    )}

                    {canPublish && eligibleLegs > 0 && (
                        <div className="flex flex-col items-end gap-1">
                            <button
                                type="button"
                                onClick={handleRetry}
                                disabled={!canRetry || retrying}
                                title={
                                    retriesLeft > 0
                                        ? `${retriesLeft} ${retriesLeft === 1 ? 'retry' : 'retries'} left`
                                        : 'No retries left'
                                }
                                className={cn(
                                    'inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-semibold transition',
                                    canRetry && !retrying
                                        ? 'bg-rose-500/10 text-rose-600 hover:bg-rose-500/15'
                                        : 'cursor-not-allowed bg-(--panel-muted) text-(--muted)',
                                )}
                            >
                                <RefreshCcw
                                    className={cn(
                                        'size-4',
                                        retrying && 'animate-spin',
                                    )}
                                    aria-hidden="true"
                                />
                                {exhausted
                                    ? 'Retries exhausted'
                                    : coolingDown
                                      ? `Retry in ${formatCountdown(remainingSeconds)}`
                                      : `Retry failed (${eligibleLegs})`}
                            </button>

                            {lastRetriedAt !== null && (
                                <p className="text-xs text-(--muted)">
                                    Last retried {formatDate(lastRetriedAt)}
                                </p>
                            )}
                        </div>
                    )}
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="lg:col-span-1">
                        <div className="overflow-hidden rounded-2xl border border-(--border) bg-(--panel)">
                            <div className="relative min-h-64 bg-(--panel-muted)">
                                {post.media[0] ? (
                                    <img
                                        src={post.media[0].url}
                                        alt=""
                                        className="absolute inset-0 size-full object-cover"
                                    />
                                ) : (
                                    <span className="absolute inset-0 grid place-items-center text-(--muted)">
                                        <FileText
                                            className="size-10"
                                            aria-hidden="true"
                                        />
                                    </span>
                                )}
                                <span className="absolute top-3 left-3">
                                    <span
                                        className={cn(
                                            'rounded-full px-3 py-1 text-xs font-semibold',
                                            postStatusChipClasses[
                                                post.status
                                                    .value as PostStatusValue
                                            ] ?? FALLBACK_STATUS_CHIP_CLASS,
                                        )}
                                    >
                                        {post.status.label}
                                    </span>
                                </span>
                            </div>

                            <div className="flex flex-col gap-3 p-5">
                                <div className="flex items-center gap-1.5 text-xs font-medium text-(--muted)">
                                    {post.scheduled_at !== null ? (
                                        <>
                                            <CalendarClock className="size-4" />
                                            Scheduled for{' '}
                                            {formatDate(post.scheduled_at)}
                                        </>
                                    ) : (
                                        <>
                                            <Clock className="size-4" />
                                            Created{' '}
                                            {formatDate(post.created_at)}
                                        </>
                                    )}
                                </div>

                                {post.title !== null && (
                                    <h1 className="text-lg font-bold text-(--text)">
                                        {post.title}
                                    </h1>
                                )}

                                <p className="text-sm whitespace-pre-wrap text-(--muted)">
                                    {post.caption || 'No caption'}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="space-y-6 lg:col-span-2">
                        <h2 className="text-xl font-extrabold tracking-tight text-(--text)">
                            Platform Targets & Performance
                        </h2>

                        {post.targets.map((target) => {
                            const brand =
                                platformBrands[
                                    target.platform.value as PlatformValue
                                ];

                            return (
                                <div
                                    key={target.id}
                                    className="overflow-hidden rounded-2xl border border-(--border) bg-(--panel) p-6 shadow-xs"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-4 border-b border-(--border) pb-4">
                                        <div className="flex items-center gap-3">
                                            <span
                                                className={cn(
                                                    'grid size-10 place-items-center rounded-xl text-white',
                                                    brand?.buttonClass ??
                                                        'bg-neutral-600',
                                                )}
                                            >
                                                {brand && (
                                                    <brand.icon className="size-5" />
                                                )}
                                            </span>
                                            <div>
                                                <h3 className="text-base font-bold text-(--text)">
                                                    {target.display_name}
                                                </h3>
                                                <p className="text-xs text-(--muted)">
                                                    {target.platform.label}
                                                </p>
                                            </div>
                                        </div>

                                        <span
                                            className={cn(
                                                'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold',
                                                target.status.value ===
                                                    'published'
                                                    ? 'bg-emerald-500/10 text-emerald-600'
                                                    : target.status.value ===
                                                        'failed'
                                                      ? 'bg-rose-500/10 text-rose-600'
                                                      : 'bg-slate-500/10 text-slate-500',
                                            )}
                                        >
                                            {target.status.value ===
                                                'published' && (
                                                <CheckCircle2 className="size-3.5" />
                                            )}
                                            {target.status.value ===
                                                'failed' && (
                                                <AlertCircle className="size-3.5" />
                                            )}
                                            {target.status.label}
                                        </span>
                                    </div>

                                    {target.error_message !== null && (
                                        <div className="mt-4 flex items-start gap-2 rounded-xl bg-rose-500/10 p-3 text-xs text-rose-600">
                                            <AlertCircle className="mt-0.5 size-4 shrink-0" />
                                            <span>{target.error_message}</span>
                                        </div>
                                    )}

                                    <div className="mt-6">
                                        <h4 className="text-xs font-bold tracking-wider text-(--muted) uppercase">
                                            Metrics Over Time (
                                            {target.metrics.length} snapshot
                                            {target.metrics.length === 1
                                                ? ''
                                                : 's'}
                                            )
                                        </h4>

                                        {target.metrics.length === 0 ? (
                                            <p className="mt-3 rounded-xl border border-dashed border-(--border) p-6 text-center text-xs text-(--muted)">
                                                No performance snapshots
                                                recorded yet.
                                            </p>
                                        ) : (
                                            <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                                {Object.keys(METRIC_LABELS).map(
                                                    (metricKey) => {
                                                        const values =
                                                            target.metrics.map(
                                                                (m) =>
                                                                    m.data[
                                                                        metricKey
                                                                    ] ?? null,
                                                            );
                                                        if (
                                                            values.every(
                                                                (v) =>
                                                                    v === null,
                                                            )
                                                        ) {
                                                            return null;
                                                        }

                                                        return (
                                                            <div
                                                                key={metricKey}
                                                                className="rounded-xl border border-(--border) bg-(--panel-muted) p-3"
                                                            >
                                                                <span className="text-xs font-medium text-(--muted)">
                                                                    {
                                                                        METRIC_LABELS[
                                                                            metricKey
                                                                        ]
                                                                    }
                                                                </span>
                                                                <div className="mt-2">
                                                                    <MetricSparkline
                                                                        values={
                                                                            values
                                                                        }
                                                                    />
                                                                </div>
                                                            </div>
                                                        );
                                                    },
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
