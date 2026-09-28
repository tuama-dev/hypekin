import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    CalendarClock,
    CheckCircle2,
    Clock,
    FileText,
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

interface PostView {
    id: string;
    status: { value: string; label: string };
    scheduled_at: string | null;
    created_at: string | null;
    caption: string;
    title: string | null;
    targets: PostTargetView[];
    media: PostMediaView[];
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

    if (workspace === null) {
        return null;
    }

    return (
        <AuthenticatedLayout>
            <Head title={post.title || post.caption || 'Post Details'} />

            <div className="flex w-full flex-col px-4 py-8 sm:px-6">
                <div className="mb-6">
                    <Link
                        href={PostController.index({ workspace: workspace.slug }).url}
                        className="inline-flex items-center gap-2 text-xs font-semibold text-(--muted) transition hover:text-(--text)"
                    >
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        Back to posts
                    </Link>
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
                                        <FileText className="size-10" aria-hidden="true" />
                                    </span>
                                )}
                                <span className="absolute top-3 left-3">
                                    <span
                                        className={cn(
                                            'rounded-full px-3 py-1 text-xs font-semibold',
                                            postStatusChipClasses[
                                                post.status.value as PostStatusValue
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
                                            Scheduled for {formatDate(post.scheduled_at)}
                                        </>
                                    ) : (
                                        <>
                                            <Clock className="size-4" />
                                            Created {formatDate(post.created_at)}
                                        </>
                                    )}
                                </div>

                                {post.title !== null && (
                                    <h1 className="text-lg font-bold text-(--text)">
                                        {post.title}
                                    </h1>
                                )}

                                <p className="whitespace-pre-wrap text-sm text-(--muted)">
                                    {post.caption || 'No caption'}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="lg:col-span-2 space-y-6">
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
                                    <div className="flex flex-wrap items-center justify-between gap-4 pb-4 border-b border-(--border)">
                                        <div className="flex items-center gap-3">
                                            <span
                                                className={cn(
                                                    'grid size-10 place-items-center rounded-xl text-white',
                                                    brand?.buttonClass ??
                                                        'bg-neutral-600',
                                                )}
                                            >
                                                {brand && <brand.icon className="size-5" />}
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
                                                target.status.value === 'published'
                                                    ? 'bg-emerald-500/10 text-emerald-600'
                                                    : target.status.value === 'failed'
                                                    ? 'bg-rose-500/10 text-rose-600'
                                                    : 'bg-slate-500/10 text-slate-500',
                                            )}
                                        >
                                            {target.status.value === 'published' && (
                                                <CheckCircle2 className="size-3.5" />
                                            )}
                                            {target.status.value === 'failed' && (
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
                                            Metrics Over Time ({target.metrics.length} snapshot
                                            {target.metrics.length === 1 ? '' : 's'})
                                        </h4>

                                        {target.metrics.length === 0 ? (
                                            <p className="mt-3 rounded-xl border border-dashed border-(--border) p-6 text-center text-xs text-(--muted)">
                                                No performance snapshots recorded yet.
                                            </p>
                                        ) : (
                                            <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                                {Object.keys(METRIC_LABELS).map((metricKey) => {
                                                    const values = target.metrics.map(
                                                        (m) => m.data[metricKey] ?? null,
                                                    );
                                                    if (values.every((v) => v === null)) {
                                                        return null;
                                                    }

                                                    return (
                                                        <div
                                                            key={metricKey}
                                                            className="rounded-xl border border-(--border) bg-(--panel-muted) p-3"
                                                        >
                                                            <span className="text-xs font-medium text-(--muted)">
                                                                {METRIC_LABELS[metricKey]}
                                                            </span>
                                                            <div className="mt-2">
                                                                <MetricSparkline values={values} />
                                                            </div>
                                                        </div>
                                                    );
                                                })}
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
