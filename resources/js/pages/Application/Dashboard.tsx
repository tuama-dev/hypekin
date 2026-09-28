import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    FileText,
    Heart,
    PenLine,
    Send,
    Users,
    Eye,
} from 'lucide-react';
import AuthenticatedLayout from '@/components/Layout/AuthenticatedLayout';
import PostController from '@/actions/App/Http/Controllers/Application/PostController';
import { MetricSparkline } from '@/components/Posts/MetricSparkline';
import { BestPostCard } from '@/components/Dashboard/BestPostCard';
import { NeedsAttentionPanel } from '@/components/Dashboard/NeedsAttentionPanel';
import { OnboardingChecklist } from '@/components/Dashboard/OnboardingChecklist';
import { PipelineSnapshot } from '@/components/Dashboard/PipelineSnapshot';
import { PlatformMix } from '@/components/Dashboard/PlatformMix';
import { SectionCard } from '@/components/Dashboard/SectionCard';
import { StatCard } from '@/components/Dashboard/StatCard';
import { TrendChart } from '@/components/Dashboard/TrendChart';
import { UpcomingSchedule } from '@/components/Dashboard/UpcomingSchedule';
import type { DashboardAnalytics } from '@/components/Dashboard/types';
import {
    FALLBACK_STATUS_CHIP_CLASS,
    postStatusChipClasses,
    type PostStatusValue,
} from '@/components/ui/postStatus';
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

interface DashboardPageProps {
    recentPosts: PostView[];
    analytics: DashboardAnalytics;
}

export default function Dashboard({
    recentPosts,
    analytics,
}: DashboardPageProps) {
    const { auth } = usePage().props;
    const workspace = auth.workspace;

    if (workspace === null) {
        return null;
    }

    const workspaceSlug = workspace.slug;
    const createUrl = PostController.create({ workspace: workspaceSlug }).url;
    const indexUrl = PostController.index({ workspace: workspaceSlug }).url;

    const showOnboarding =
        !analytics.onboarding.has_accounts || !analytics.onboarding.has_posts;

    const trendReach = analytics.trend.map((point) => point.reach);
    const trendImpressions = analytics.trend.map((point) => point.impressions);
    const trendEngagements = analytics.trend.map((point) => point.engagements);

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />

            <div className="flex w-full flex-col px-4 py-8 sm:px-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-extrabold tracking-tight text-(--text)">
                            Dashboard
                        </h1>
                        <p className="mt-1 text-sm text-(--muted)">
                            Workspace overview and publishing performance for{' '}
                            <strong className="font-semibold">
                                {workspace.name}
                            </strong>
                            .
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Link
                            href={createUrl}
                            className="inline-flex items-center gap-2 rounded-lg bg-linear-to-r from-(--color-accent-start) to-(--color-accent-end) px-4 py-2 text-xs font-bold text-(--color-accent-ink) shadow-lg shadow-[color:var(--color-accent-end)]/20 transition hover:-translate-y-0.5 hover:brightness-105"
                        >
                            <PenLine className="size-4" />
                            Create post
                        </Link>
                        <Link
                            href={indexUrl}
                            className="inline-flex items-center gap-2 rounded-lg border border-(--border) px-4 py-2 text-xs font-bold text-(--text) transition hover:bg-(--panel-muted)"
                        >
                            View all posts
                            <ArrowRight className="size-4" />
                        </Link>
                    </div>
                </header>

                {showOnboarding && (
                    <section className="mt-8 rounded-2xl border border-(--border) bg-(--panel) p-6">
                        <h2 className="text-lg font-extrabold tracking-tight text-(--text)">
                            Get your workspace publishing
                        </h2>
                        <p className="mt-1 text-sm text-(--muted)">
                            A few quick steps to set up schedules, media, and
                            your first post.
                        </p>
                        <div className="mt-5">
                            <OnboardingChecklist
                                workspaceSlug={workspaceSlug}
                                hasAccounts={analytics.onboarding.has_accounts}
                                hasMedia={analytics.onboarding.has_media}
                                hasPosts={analytics.onboarding.has_posts}
                            />
                        </div>
                    </section>
                )}

                {!showOnboarding && (
                    <>
                        <section className="mt-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
                            <StatCard
                                label="Reach"
                                value={analytics.kpis.reach}
                                icon={Users}
                                hint="Unique people reached, last 30 days"
                                iconClassName="bg-sky-500/10 text-sky-600"
                                sparklineClassName="stroke-sky-500"
                                sparklineValues={trendReach}
                            />
                            <StatCard
                                label="Impressions"
                                value={analytics.kpis.impressions}
                                icon={Eye}
                                hint="Total views served, last 30 days"
                                iconClassName="bg-violet-500/10 text-violet-600"
                                sparklineClassName="stroke-violet-500"
                                sparklineValues={trendImpressions}
                            />
                            <StatCard
                                label="Engagements"
                                value={analytics.kpis.engagements}
                                icon={Heart}
                                hint="Likes, comments & shares, last 30 days"
                                iconClassName="bg-rose-500/10 text-rose-600"
                                sparklineClassName="stroke-rose-500"
                                sparklineValues={trendEngagements}
                            />
                            <StatCard
                                label="Posts published"
                                value={analytics.kpis.posts_published_30d}
                                icon={Send}
                                hint={`${analytics.kpis.accounts_connected} connected · ${analytics.kpis.unread_notifications} unread`}
                                iconClassName="bg-emerald-500/10 text-emerald-600"
                            />
                        </section>

                        <section className="mt-4 grid gap-4 lg:grid-cols-3">
                            <div className="flex flex-col gap-4 lg:col-span-2">
                                <TrendChart data={analytics.trend} />
                                {analytics.best_post && (
                                    <BestPostCard
                                        workspaceSlug={workspaceSlug}
                                        post={analytics.best_post}
                                    />
                                )}
                            </div>

                            <div className="flex flex-col gap-4">
                                <SectionCard
                                    title="Platform mix"
                                    subtitle="Reach share by platform"
                                >
                                    <PlatformMix
                                        items={analytics.platform_mix}
                                    />
                                </SectionCard>
                                <SectionCard
                                    title="Post pipeline"
                                    subtitle="Posts by status"
                                >
                                    <PipelineSnapshot
                                        items={analytics.pipeline}
                                    />
                                </SectionCard>
                            </div>
                        </section>

                        <section className="mt-4 grid gap-4 lg:grid-cols-3">
                            <SectionCard
                                title="Needs attention"
                                subtitle="Posts that failed to publish"
                                className="lg:col-span-2"
                            >
                                <NeedsAttentionPanel
                                    workspaceSlug={workspaceSlug}
                                    items={analytics.needs_attention.items}
                                />
                            </SectionCard>
                            <SectionCard
                                title="Upcoming"
                                subtitle="Next scheduled posts"
                            >
                                <UpcomingSchedule
                                    workspaceSlug={workspaceSlug}
                                    items={analytics.upcoming}
                                />
                            </SectionCard>
                        </section>
                    </>
                )}

                <section className="mt-8">
                    <h2 className="text-lg font-extrabold tracking-tight text-(--text)">
                        Recent Posts & Performance
                    </h2>

                    {recentPosts.length === 0 ? (
                        <div className="mt-4 rounded-2xl border border-dashed border-(--border) bg-(--panel) p-8 text-center">
                            <span className="mx-auto grid size-12 place-items-center rounded-full bg-(--panel-muted) text-(--muted)">
                                <Send className="size-5" />
                            </span>
                            <h3 className="mt-4 text-base font-bold text-(--text)">
                                No posts published yet
                            </h3>
                            <p className="mx-auto mt-1 max-w-sm text-sm text-(--muted)">
                                Create your first post to start tracking reach,
                                impressions, and engagement across your
                                channels.
                            </p>
                        </div>
                    ) : (
                        <div className="mt-4 grid gap-4 lg:grid-cols-2">
                            {recentPosts.map((post) => {
                                const showUrl = PostController.show({
                                    workspace: workspaceSlug,
                                    post: post.id,
                                }).url;

                                return (
                                    <Link
                                        key={post.id}
                                        href={showUrl}
                                        className="flex flex-col overflow-hidden rounded-2xl border border-(--border) bg-(--panel) transition hover:border-(--color-accent-start) hover:shadow-md"
                                    >
                                        <div className="flex items-center gap-4 border-b border-(--border) p-4">
                                            <div className="relative size-14 shrink-0 overflow-hidden rounded-xl bg-(--panel-muted)">
                                                {post.media[0] ? (
                                                    <img
                                                        src={post.media[0].url}
                                                        alt=""
                                                        className="absolute inset-0 size-full object-cover"
                                                    />
                                                ) : (
                                                    <span className="absolute inset-0 grid place-items-center text-(--muted)">
                                                        <FileText className="size-5" />
                                                    </span>
                                                )}
                                            </div>

                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span
                                                        className={cn(
                                                            'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                                            postStatusChipClasses[
                                                                post.status
                                                                    .value as PostStatusValue
                                                            ] ??
                                                                FALLBACK_STATUS_CHIP_CLASS,
                                                        )}
                                                    >
                                                        {post.status.label}
                                                    </span>
                                                </div>

                                                {post.title !== null && (
                                                    <p className="mt-1 truncate text-sm font-bold text-(--text)">
                                                        {post.title}
                                                    </p>
                                                )}

                                                <p className="truncate text-xs text-(--muted)">
                                                    {post.caption ||
                                                        'Untitled post'}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="grid gap-3 bg-(--panel-muted)/50 p-4">
                                            {post.targets.map((target) => {
                                                const impressions =
                                                    target.metrics
                                                        .map(
                                                            (m) =>
                                                                m.data
                                                                    .impressions ??
                                                                m.data.views ??
                                                                null,
                                                        )
                                                        .filter(
                                                            (v): v is number =>
                                                                v !== null,
                                                        );

                                                return (
                                                    <div
                                                        key={target.id}
                                                        className="flex items-center justify-between gap-2 text-xs"
                                                    >
                                                        <div className="flex items-center gap-2 font-semibold text-(--text)">
                                                            <span className="text-(--muted)">
                                                                {
                                                                    target.display_name
                                                                }
                                                            </span>
                                                        </div>
                                                        <MetricSparkline
                                                            values={impressions}
                                                        />
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
