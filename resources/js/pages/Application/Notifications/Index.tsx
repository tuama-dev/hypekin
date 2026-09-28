import { Head, InfiniteScroll, router, usePage } from '@inertiajs/react';
import { Bell, BellOff, Loader2 } from 'lucide-react';
import AuthenticatedLayout from '@/components/Layout/AuthenticatedLayout';
import NotificationController from '@/actions/App/Http/Controllers/Application/NotificationController';
import {
    platformBrands,
    type PlatformValue,
} from '@/components/ui/platformBrands';
import { timeAgo } from '@/lib/timeAgo';
import { cn } from '@/lib/utils';
import type { Notification } from '@/types/auth';

interface NotificationPaginator {
    data: Notification[];
    total: number;
}

interface NotificationsIndexPageProps {
    notifications: NotificationPaginator;
}

export default function NotificationsIndex({
    notifications,
}: NotificationsIndexPageProps) {
    const { auth } = usePage().props;
    const workspace = auth.workspace;

    if (workspace === null) {
        return null;
    }

    const workspaceSlug = workspace.slug;

    function open(notification: Notification) {
        const destination = `/app/${notification.data.workspace_slug}/posts/${notification.data.post_id}`;

        if (notification.read_at !== null) {
            router.visit(destination);

            return;
        }

        router.patch(
            NotificationController.read({
                workspace: workspaceSlug,
                notification: notification.id,
            }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => router.visit(destination),
            },
        );
    }

    return (
        <AuthenticatedLayout>
            <Head title="Notifications" />

            <div className="flex w-full flex-col px-4 py-8 sm:px-6">
                <header className="flex flex-wrap items-baseline justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-extrabold tracking-tight text-(--text)">
                            Notifications
                        </h1>
                        <p className="mt-1 text-sm text-(--muted)">
                            Activity across all your workspaces. Click one to
                            mark it read and open the related post.
                        </p>
                    </div>
                    <span className="rounded-full bg-(--panel-muted) px-3 py-1 text-xs font-semibold text-(--muted)">
                        {notifications.total}{' '}
                        {notifications.total === 1
                            ? 'notification'
                            : 'notifications'}
                    </span>
                </header>

                <InfiniteScroll data="notifications">
                    {({ loadingNext }) => (
                        <div>
                            {notifications.data.length === 0 ? (
                                <section className="mt-6 rounded-2xl border border-dashed border-(--border) bg-(--panel) p-8 text-center">
                                    <span className="mx-auto grid size-12 place-items-center rounded-full bg-(--panel-muted) text-(--muted)">
                                        <BellOff
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <h2 className="mt-4 text-base font-bold text-(--text)">
                                        No notifications yet
                                    </h2>
                                    <p className="mx-auto mt-1 max-w-sm text-sm text-(--muted)">
                                        You'll see publishing failures here as
                                        they happen.
                                    </p>
                                </section>
                            ) : (
                                <>
                                    <ul className="mt-6 grid gap-3">
                                        {notifications.data.map(
                                            (notification, index) => {
                                                const brand =
                                                    platformBrands[
                                                        notification.data
                                                            .platform as PlatformValue
                                                    ];

                                                return (
                                                    <li
                                                        key={notification.id}
                                                        className="relative"
                                                    >
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                open(
                                                                    notification,
                                                                )
                                                            }
                                                            className={cn(
                                                                'flex w-full items-center gap-4 rounded-2xl border border-(--border) bg-(--panel) p-4 text-left transition hover:border-(--color-accent-start) hover:shadow-md focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none',
                                                                notification.read_at ===
                                                                        null &&
                                                                    index === 0 &&
                                                                    'bg-(--panel-muted)',
                                                            )}
                                                        >
                                                            <span
                                                                className={cn(
                                                                    'grid size-10 shrink-0 place-items-center rounded-xl text-white',
                                                                    brand?.buttonClass ??
                                                                        'bg-neutral-600',
                                                                )}
                                                                aria-hidden="true"
                                                            >
                                                                {brand ? (
                                                                    <brand.icon className="size-5" />
                                                                ) : (
                                                                    <Bell className="size-5" />
                                                                )}
                                                            </span>

                                                            <span className="min-w-0 flex-1">
                                                                <span className="flex items-center gap-2">
                                                                    {notification.read_at ===
                                                                        null && (
                                                                        <span
                                                                            className="size-2 shrink-0 rounded-full bg-(--color-accent-end)"
                                                                            aria-hidden="true"
                                                                        />
                                                                    )}
                                                                    <span className="truncate text-sm font-bold text-(--text)">
                                                                        {
                                                                            notification
                                                                                .data
                                                                                .title
                                                                        }
                                                                    </span>
                                                                </span>
                                                                <span className="mt-0.5 block truncate text-sm text-(--muted)">
                                                                    {notification.data
                                                                        .message ??
                                                                        ''}
                                                                </span>
                                                                <span className="mt-0.5 block text-xs text-(--muted)">
                                                                    {notification.data
                                                                        .workspace_slug
                                                                        ? `${notification.data.workspace_slug} · `
                                                                        : ''}
                                                                    {timeAgo(
                                                                        notification.created_at,
                                                                    )}
                                                                </span>
                                                            </span>
                                                        </button>
                                                    </li>
                                                );
                                            },
                                        )}
                                    </ul>

                                    {loadingNext && (
                                        <p className="mt-6 flex items-center justify-center gap-2 text-sm text-(--muted)">
                                            <Loader2
                                                className="size-4 animate-spin"
                                                aria-hidden="true"
                                            />
                                            Loading more…
                                        </p>
                                    )}
                                </>
                            )}
                        </div>
                    )}
                </InfiniteScroll>
            </div>
        </AuthenticatedLayout>
    );
}