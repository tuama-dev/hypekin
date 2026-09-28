import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import {
    AlertCircle,
    CalendarClock,
    CheckCircle2,
    Clock,
    FileText,
    LayoutGrid,
    Plus,
    Search,
    Send,
    X,
} from 'lucide-react';
import AuthenticatedLayout from '@/components/Layout/AuthenticatedLayout';
import { AccountFilterBar } from '@/components/Posts/AccountFilterBar';
import { RescheduleModal } from '@/components/Posts/RescheduleModal';
import PostController from '@/actions/App/Http/Controllers/Application/PostController';
import {
    FALLBACK_STATUS_CHIP_CLASS,
    postStatusChipClasses,
    postStatusSwatchClasses,
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

interface PostTargetView {
    id: string;
    platform: { value: string; label: string };
    display_name: string;
    status: { value: string; label: string };
    error_message: string | null;
}

interface SocialAccountView {
    id: string;
    platform: { value: string; label: string };
    display_name: string;
    status: { value: string; label: string };
    avatar_url: string | null;
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

interface PostsIndexPageProps {
    posts: PostView[];
    publishablePlatforms: string[];
    accounts: SocialAccountView[];
    filters: {
        status: string | null;
        search: string;
        account: string | null;
    };
}

type FilterSelections = {
    status: string | null;
    search: string;
    account: string | null;
};

type ViewMode = 'grid' | 'kanban';

const VIEW_STORAGE_KEY = 'lareact:posts-view';

const STATUS_TABS: { value: string; label: string }[] = [
    { value: '', label: 'All' },
    { value: 'scheduled', label: 'Scheduled' },
    { value: 'publishing', label: 'Publishing' },
    { value: 'published', label: 'Published' },
    { value: 'failed', label: 'Failed' },
    { value: 'draft', label: 'Drafts' },
];

/**
 * Board columns follow the publishing pipeline: work not yet sent, work in
 * flight, work that landed, and work that needs attention.
 */
const KANBAN_COLUMNS: { value: PostStatusValue; label: string }[] = [
    { value: 'draft', label: 'Drafts' },
    { value: 'scheduled', label: 'Scheduled' },
    { value: 'publishing', label: 'Publishing' },
    { value: 'published', label: 'Published' },
    { value: 'failed', label: 'Failed' },
    { value: 'canceled', label: 'Canceled' },
];

function listWithConjunction(
    items: string[],
    conjunction: 'and' | 'or',
): string {
    if (items.length <= 1) {
        return items.join('');
    }

    return `${items.slice(0, -1).join(', ')} ${conjunction} ${items.at(-1)}`;
}

function filterQuery(filters: FilterSelections): Record<string, string> {
    return {
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.search !== '' ? { search: filters.search } : {}),
        ...(filters.account ? { account: filters.account } : {}),
    };
}

function postsIndexUrl(
    workspaceSlug: string,
    filters: FilterSelections,
): string {
    return PostController.index(
        { workspace: workspaceSlug },
        { query: filterQuery(filters) },
    ).url;
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

export default function PostsIndex({
    posts,
    publishablePlatforms,
    accounts,
    filters,
}: PostsIndexPageProps) {
    const { auth } = usePage().props;
    const workspace = auth.workspace;

    const [search, setSearch] = useState(filters.search);
    const [view, setView] = useState<ViewMode>(() => {
        if (typeof window === 'undefined') {
            return 'grid';
        }

        return window.localStorage.getItem(VIEW_STORAGE_KEY) === 'kanban'
            ? 'kanban'
            : 'grid';
    });
    const [rescheduling, setRescheduling] = useState<PostView | null>(null);
    const isFirstRender = useRef(true);

    useEffect(() => {
        setSearch(filters.search);
    }, [filters.search]);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        if (search === filters.search) {
            return;
        }

        const timeout = window.setTimeout(() => {
            const url = postsIndexUrl(workspace?.slug ?? '', {
                status: filters.status,
                search,
                account: filters.account,
            });
            const data = filterQuery({
                status: filters.status,
                search,
                account: filters.account,
            });

            router.get(url, data, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 350);

        return () => window.clearTimeout(timeout);
    }, [search, filters.search, filters.status, workspace?.slug]);

    function changeView(next: ViewMode) {
        setView(next);
        window.localStorage.setItem(VIEW_STORAGE_KEY, next);
    }

    const postsByStatus = useMemo(() => {
        const grouped = new Map<string, PostView[]>();

        for (const post of posts) {
            const bucket = grouped.get(post.status.value) ?? [];
            bucket.push(post);
            grouped.set(post.status.value, bucket);
        }

        return grouped;
    }, [posts]);

    if (workspace === null) {
        return null;
    }

    const currentWorkspace = workspace;
    const hasFilters =
        filters.status !== null ||
        filters.search !== '' ||
        filters.account !== null;
    const activeAccount =
        accounts.find((account) => account.id === filters.account) ?? null;
    const failedTargets = posts.reduce(
        (count, post) =>
            count +
            post.targets.filter((target) => target.status.value === 'failed')
                .length,
        0,
    );

    return (
        <AuthenticatedLayout>
            <Head title="Posts" />

            <div className="flex w-full flex-col px-4 py-8 sm:px-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-extrabold tracking-tight text-(--text)">
                            Posts
                        </h1>
                        <p className="mt-1 text-sm text-(--muted)">
                            Everything your workspace has written for{' '}
                            <strong className="font-semibold">
                                {currentWorkspace.name}
                            </strong>
                            .
                        </p>
                    </div>

                    <Link
                        href={
                            PostController.create({
                                workspace: currentWorkspace.slug,
                            }).url
                        }
                        className="inline-flex items-center gap-2 rounded-lg bg-linear-to-r from-[var(--color-accent-start)] to-[var(--color-accent-end)] px-5 py-2.5 text-sm font-bold text-[var(--color-accent-ink)] shadow-md shadow-[color:var(--color-accent-end)]/20 transition hover:-translate-y-0.5 hover:brightness-105 focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none"
                    >
                        <Plus className="size-4" aria-hidden="true" />
                        New post
                    </Link>
                </header>

                {posts.length > 0 || hasFilters ? (
                    <>
                        <AccountFilterBar
                            accounts={accounts}
                            activeAccountId={filters.account}
                            hrefFor={(accountId) =>
                                postsIndexUrl(currentWorkspace.slug, {
                                    status: filters.status,
                                    search: filters.search,
                                    account: accountId,
                                })
                            }
                        />

                        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                            <nav
                                aria-label="Filter posts by status"
                                className="flex flex-wrap items-center gap-1 rounded-xl border border-(--border) bg-(--panel) p-1"
                            >
                                {STATUS_TABS.map((tab) => {
                                    const isActive =
                                        (filters.status ?? '') === tab.value;

                                    return (
                                        <Link
                                            key={tab.label}
                                            href={postsIndexUrl(
                                                currentWorkspace.slug,
                                                {
                                                    status: tab.value || null,
                                                    search: filters.search,
                                                    account: filters.account,
                                                },
                                            )}
                                            preserveScroll
                                            className={cn(
                                                'rounded-lg px-3 py-1.5 text-xs font-semibold transition focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none',
                                                isActive
                                                    ? 'bg-(--panel-muted) text-(--text)'
                                                    : 'text-(--muted) hover:text-(--text)',
                                            )}
                                        >
                                            {tab.label}
                                        </Link>
                                    );
                                })}
                            </nav>

                            <div className="flex w-full items-center gap-3 sm:w-auto">
                                <div className="relative min-w-0 flex-1 sm:w-64 sm:flex-none">
                                    <span
                                        className="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-(--muted)"
                                        aria-hidden="true"
                                    >
                                        <Search className="size-4" />
                                    </span>
                                    <input
                                        type="search"
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                        placeholder="Search captions and titles"
                                        aria-label="Search posts"
                                        className="h-10 w-full rounded-xl border border-(--border) bg-(--panel) pr-9 pl-10 text-sm text-(--text) transition outline-none placeholder:text-(--muted)/75 focus:border-(--color-accent-start) focus:ring-4 focus:ring-[color:var(--color-accent-start)]/15"
                                    />
                                    {search !== '' && (
                                        <button
                                            type="button"
                                            onClick={() => setSearch('')}
                                            aria-label="Clear search"
                                            className="absolute inset-y-0 right-3 grid place-items-center text-(--muted) transition hover:text-(--text) focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none"
                                        >
                                            <X className="size-4" />
                                        </button>
                                    )}
                                </div>

                                <div
                                    role="group"
                                    aria-label="Change posts layout"
                                    className="flex shrink-0 items-center gap-1 rounded-xl border border-(--border) bg-(--panel) p-1"
                                >
                                    <ViewToggleButton
                                        isActive={view === 'grid'}
                                        onClick={() => changeView('grid')}
                                        label="Grid view"
                                    >
                                        <LayoutGrid
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </ViewToggleButton>
                                    <ViewToggleButton
                                        isActive={view === 'kanban'}
                                        onClick={() => changeView('kanban')}
                                        label="Board view"
                                    >
                                        <KanbanIcon />
                                    </ViewToggleButton>
                                </div>
                            </div>
                        </div>
                    </>
                ) : null}

                {posts.length === 0 ? (
                    <section className="mt-6 rounded-2xl border border-dashed border-(--border) bg-(--panel) p-8 text-center">
                        <span className="mx-auto grid size-12 place-items-center rounded-full bg-(--panel-muted) text-(--muted)">
                            {hasFilters ? (
                                <Search className="size-5" aria-hidden="true" />
                            ) : (
                                <Send className="size-5" aria-hidden="true" />
                            )}
                        </span>
                        <h2 className="mt-4 text-base font-bold text-(--text)">
                            {hasFilters ? 'No matching posts' : 'No posts yet'}
                        </h2>
                        <p className="mx-auto mt-1 max-w-sm text-sm text-(--muted)">
                            {hasFilters ? (
                                <>
                                    Nothing matches the current filter.{' '}
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.get(
                                                PostController.index({
                                                    workspace:
                                                        currentWorkspace.slug,
                                                }).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                        className="font-semibold text-(--text) underline underline-offset-2"
                                    >
                                        Clear filters
                                    </button>{' '}
                                    to see everything.
                                </>
                            ) : (
                                <>
                                    Create your first post with a built-in
                                    composer, then it publishes to your
                                    connected{' '}
                                    {listWithConjunction(
                                        publishablePlatforms,
                                        'and',
                                    )}{' '}
                                    accounts.
                                </>
                            )}
                        </p>
                    </section>
                ) : (
                    <>
                        <p className="mt-4 text-xs font-medium text-(--muted)">
                            {posts.length} post
                            {posts.length === 1 ? '' : 's'}
                            {filters.status !== null && (
                                <>
                                    {' '}
                                    ·{' '}
                                    {
                                        STATUS_TABS.find(
                                            (tab) =>
                                                tab.value === filters.status,
                                        )?.label
                                    }
                                </>
                            )}
                            {activeAccount !== null && (
                                <>
                                    {' '}
                                    · {activeAccount.platform.label} ·{' '}
                                    {activeAccount.display_name}
                                </>
                            )}
                            {failedTargets > 0 && (
                                <> · {failedTargets} failed target(s)</>
                            )}
                        </p>

                        {view === 'grid' ? (
                            <ul className="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                                {posts.map((post) => (
                                    <GridCard
                                        key={post.id}
                                        workspaceSlug={currentWorkspace.slug}
                                        post={post}
                                        onReschedule={() =>
                                            setRescheduling(post)
                                        }
                                    />
                                ))}
                            </ul>
                        ) : (
                            <div className="-mx-4 mt-3 overflow-x-auto px-4 pb-2 sm:-mx-6 sm:px-6">
                                <div className="flex min-w-max gap-4">
                                    {KANBAN_COLUMNS.map((column) => {
                                        const columnPosts =
                                            postsByStatus.get(column.value) ??
                                            [];

                                        return (
                                            <section
                                                key={column.value}
                                                className="flex w-72 shrink-0 flex-col rounded-2xl border border-(--border) bg-(--panel-muted) p-3"
                                            >
                                                <h2 className="flex items-center justify-between gap-2 px-1 text-xs font-bold tracking-wide text-(--muted) uppercase">
                                                    <span className="inline-flex items-center gap-2">
                                                        <span
                                                            aria-hidden="true"
                                                            className={cn(
                                                                'size-2 rounded-full',
                                                                postStatusSwatchClasses[
                                                                    column.value
                                                                ],
                                                            )}
                                                        />
                                                        {column.label}
                                                    </span>
                                                    <span className="rounded-full bg-(--panel) px-2 py-0.5 text-[11px] font-semibold text-(--muted)">
                                                        {columnPosts.length}
                                                    </span>
                                                </h2>

                                                {columnPosts.length === 0 ? (
                                                    <p className="mt-2 rounded-xl border border-dashed border-(--border) px-3 py-6 text-center text-xs text-(--muted)">
                                                        Nothing here
                                                    </p>
                                                ) : (
                                                    <ul className="mt-2 grid gap-2">
                                                        {columnPosts.map(
                                                            (post) => (
                                                                <li
                                                                    key={
                                                                        post.id
                                                                    }
                                                                >
                                                                     <KanbanCard
                                                                         workspaceSlug={
                                                                             currentWorkspace.slug
                                                                         }
                                                                         post={
                                                                             post
                                                                         }
                                                                         onReschedule={() =>
                                                                             setRescheduling(
                                                                                 post,
                                                                             )
                                                                         }
                                                                     />
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                )}
                                            </section>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </>
                )}

                {rescheduling !== null && (
                    <RescheduleModal
                        workspaceSlug={currentWorkspace.slug}
                        post={rescheduling}
                        onClose={() => setRescheduling(null)}
                    />
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function ViewToggleButton({
    isActive,
    onClick,
    label,
    children,
}: {
    isActive: boolean;
    onClick: () => void;
    label: string;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={isActive}
            aria-label={label}
            title={label}
            className={cn(
                'grid size-8 place-items-center rounded-lg transition focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none',
                isActive
                    ? 'bg-(--panel-muted) text-(--text)'
                    : 'text-(--muted) hover:text-(--text)',
            )}
        >
            {children}
        </button>
    );
}

function KanbanIcon() {
    return (
        <span aria-hidden="true" className="grid size-4 grid-cols-3 gap-0.5">
            <span className="rounded-[1px] bg-current" />
            <span className="rounded-[1px] bg-current" />
            <span className="rounded-[1px] bg-current" />
        </span>
    );
}

function StatusChip({ post }: { post: PostView }) {
    return (
        <span
            className={cn(
                'rounded-full px-2.5 py-1 text-xs font-semibold',
                postStatusChipClasses[post.status.value as PostStatusValue] ??
                    FALLBACK_STATUS_CHIP_CLASS,
            )}
        >
            {post.status.label}
        </span>
    );
}

function PostTimestamp({ post }: { post: PostView }) {
    if (post.scheduled_at !== null) {
        return (
            <span className="inline-flex items-center gap-1.5 text-xs font-medium text-(--muted)">
                <Clock className="size-3.5" aria-hidden="true" />
                {formatDate(post.scheduled_at)}
            </span>
        );
    }

    return (
        <span className="text-xs font-medium text-(--muted)">
            Created {formatDate(post.created_at)}
        </span>
    );
}

function TargetBadges({
    post,
    showNames,
}: {
    post: PostView;
    showNames: boolean;
}) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {post.targets.map((target) => {
                const brand =
                    platformBrands[target.platform.value as PlatformValue];
                const icon = <TargetIcon platform={target.platform.value} />;

                if (!showNames) {
                    return (
                        <span
                            key={target.id}
                            title={`${target.display_name} · ${target.platform.label} · ${target.status.label}`}
                            className="relative inline-flex"
                        >
                            {icon}
                            {target.status.value === 'failed' && (
                                <AlertCircle
                                    className="absolute -top-0.5 -right-0.5 size-3 text-rose-500"
                                    aria-hidden="true"
                                />
                            )}
                        </span>
                    );
                }

                return (
                    <span
                        key={target.id}
                        title={
                            target.error_message ??
                            `${target.display_name} · ${target.platform.label}`
                        }
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
                            brand?.chipClass ??
                                'bg-(--panel-muted) text-(--muted)',
                        )}
                    >
                        {target.status.value === 'published' && (
                            <CheckCircle2
                                className="size-3.5 text-emerald-500"
                                aria-hidden="true"
                            />
                        )}
                        {target.status.value === 'failed' && (
                            <AlertCircle
                                className="size-3.5 text-rose-500"
                                aria-hidden="true"
                            />
                        )}
                        {icon}
                        {target.display_name}
                    </span>
                );
            })}
        </div>
    );
}

function TargetIcon({ platform }: { platform: string }) {
    const brand = platformBrands[platform as PlatformValue];

    if (!brand) {
        return null;
    }

    const Icon = brand.icon;

    return <Icon className="size-3.5 shrink-0" aria-label={brand.label} />;
}

function Failures({ post }: { post: PostView }) {
    const failedTargets = post.targets.filter(
        (target) => target.error_message !== null,
    );

    if (failedTargets.length === 0) {
        return null;
    }

    return (
        <ul className="grid gap-1 text-xs text-rose-500">
            {failedTargets.map((target) => (
                <li key={target.id} className="flex items-start gap-1.5">
                    <AlertCircle
                        className="mt-0.5 size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span className="min-w-0">
                        <span className="font-semibold">
                            {target.display_name}
                        </span>{' '}
                        — {target.error_message}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function RescheduleButton({ onClick }: { onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex items-center gap-1.5 rounded-lg border border-(--border) px-3 py-1.5 text-xs font-semibold text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text) focus:ring-4 focus:ring-[color:var(--color-line)]/40 focus:outline-none"
        >
            <CalendarClock className="size-3.5" aria-hidden="true" />
            Reschedule
        </button>
    );
}

function GridCard({
    workspaceSlug,
    post,
    onReschedule,
}: {
    workspaceSlug: string;
    post: PostView;
    onReschedule: () => void;
}) {
    const showUrl = PostController.show({
        workspace: workspaceSlug,
        post: post.id,
    }).url;

    return (
        <li className="flex flex-col overflow-hidden rounded-2xl border border-(--border) bg-(--panel) transition hover:border-(--color-accent-start) hover:shadow-md">
            <Link href={showUrl} className="flex flex-1 flex-col">
                <div className="relative min-h-52 bg-(--panel-muted)">
                    {post.media[0] ? (
                        <img
                            src={post.media[0].url}
                            alt=""
                            loading="lazy"
                            className="absolute inset-0 size-full object-cover"
                        />
                    ) : (
                        <span className="absolute inset-0 grid place-items-center text-(--muted)">
                            <FileText className="size-8" aria-hidden="true" />
                        </span>
                    )}

                    <span className="absolute top-2.5 left-2.5">
                        <StatusChip post={post} />
                    </span>
                </div>

                <div className="flex flex-1 flex-col gap-2 p-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <PostTimestamp post={post} />
                    </div>

                    {post.title !== null && (
                        <p className="text-sm font-bold text-(--text)">
                            {post.title}
                        </p>
                    )}

                    <p className="line-clamp-3 text-sm text-(--muted)">
                        {post.caption || 'Untitled post'}
                    </p>

                    <div className="mt-auto grid gap-3 pt-1">
                        <TargetBadges post={post} showNames />

                        <Failures post={post} />
                    </div>
                </div>
            </Link>

            {post.status.value === 'scheduled' && (
                <div className="p-4 pt-0">
                    <RescheduleButton onClick={onReschedule} />
                </div>
            )}
        </li>
    );
}

function KanbanCard({
    workspaceSlug,
    post,
    onReschedule,
}: {
    workspaceSlug: string;
    post: PostView;
    onReschedule: () => void;
}) {
    const showUrl = PostController.show({
        workspace: workspaceSlug,
        post: post.id,
    }).url;

    return (
        <article className="grid gap-2 rounded-xl border border-(--border) bg-(--panel) p-3 transition hover:border-(--color-accent-start)">
            <Link href={showUrl} className="grid gap-2">
                <div className="flex items-start gap-2">
                    {post.media[0] ? (
                        <img
                            src={post.media[0].url}
                            alt=""
                            loading="lazy"
                            className="size-10 shrink-0 rounded-lg border border-(--border) object-cover"
                        />
                    ) : (
                        <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-(--panel-muted) text-(--muted)">
                            <FileText className="size-4" aria-hidden="true" />
                        </span>
                    )}

                    <div className="min-w-0 flex-1">
                        {post.title !== null && (
                            <p className="truncate text-xs font-bold text-(--text)">
                                {post.title}
                            </p>
                        )}
                        <p className="line-clamp-2 text-xs text-(--muted)">
                            {post.caption || 'Untitled post'}
                        </p>
                    </div>
                </div>

                <PostTimestamp post={post} />

                <TargetBadges post={post} showNames={false} />

                <Failures post={post} />
            </Link>

            {post.status.value === 'scheduled' && (
                <div>
                    <RescheduleButton onClick={onReschedule} />
                </div>
            )}
        </article>
    );
}
