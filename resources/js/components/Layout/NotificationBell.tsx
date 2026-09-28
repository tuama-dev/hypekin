import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Bell, CheckCheck, ExternalLink, List } from 'lucide-react';
import { toast } from 'sonner';

import type { Notification } from '@/types/auth';
import NotificationController from '@/actions/App/Http/Controllers/Application/NotificationController';
import { timeAgo } from '@/lib/timeAgo';

export default function NotificationBell() {
    const { props } = usePage();
    const [isOpen, setIsOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);

    const notifications = props.auth?.notifications ?? [];
    const unreadCount = Number(props.auth?.unread_count ?? 0);
    const workspaceSlug = props.auth?.workspace?.slug ?? null;

    useEffect(() => {
        function handlePointerDown(event: PointerEvent) {
            if (!menuRef.current?.contains(event.target as Node)) {
                setIsOpen(false);
            }
        }

        function handleKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setIsOpen(false);
            }
        }

        document.addEventListener('pointerdown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.removeEventListener('pointerdown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, []);

    function markAllRead() {
        if (workspaceSlug === null) {
            return;
        }

        router.post(
            NotificationController.readAll({ workspace: workspaceSlug }).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success('All notifications marked as read.'),
            },
        );
    }

    function open(notification: Notification) {
        setIsOpen(false);

        if (workspaceSlug === null) {
            return;
        }

        const destination = notification.data.post_id
            ? `/app/${notification.data.workspace_slug}/posts/${notification.data.post_id}`
            : `/app/${notification.data.workspace_slug}/posts`;

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
                onFinish: () => router.visit(destination),
            },
        );
    }

    return (
        <div ref={menuRef} className="relative">
            <button
                className="relative grid size-9 place-items-center rounded-lg text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text)"
                type="button"
                onClick={() => setIsOpen((current) => !current)}
                aria-label="Notifications"
                aria-haspopup="menu"
                aria-expanded={isOpen}
            >
                <Bell />
                {unreadCount > 0 && (
                    <span className="absolute top-1.5 right-1.5 flex min-w-4 items-center justify-center rounded-full bg-(--color-accent-end) px-1 text-[0.65rem] leading-4 font-bold text-white">
                        {unreadCount}
                    </span>
                )}
            </button>
            {isOpen && (
                <div
                    role="menu"
                    className="absolute right-0 z-50 mt-2 w-80 origin-top-right rounded-lg border border-(--surface-border) bg-(--surface) p-1 shadow-(--surface-shadow)"
                >
                    <div className="flex items-center justify-between px-3 py-2">
                        <span className="text-sm font-semibold text-(--text)">
                            Notifications
                        </span>
                        {unreadCount > 0 && (
                            <button
                                type="button"
                                role="menuitem"
                                onClick={markAllRead}
                                className="flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text)"
                            >
                                <CheckCheck className="size-3.5" />
                                Mark all read
                            </button>
                        )}
                    </div>
                    <div className="max-h-96 overflow-y-auto">
                        {notifications.length === 0 ? (
                            <p className="px-3 py-6 text-center text-sm text-(--muted)">
                                No notifications yet.
                            </p>
                        ) : (
                            notifications.map((notification: Notification) => (
                                <button
                                    key={notification.id}
                                    type="button"
                                    role="menuitem"
                                    onClick={() => open(notification)}
                                    className={`flex w-full flex-col gap-0.5 rounded-lg px-3 py-2.5 text-left transition hover:bg-(--panel-muted) ${
                                        notification.read_at === null
                                            ? 'bg-(--panel-muted)'
                                            : ''
                                    }`}
                                >
                                    <span className="flex items-center gap-1.5 text-sm font-semibold text-(--text)">
                                        <span
                                            className={`size-1.5 rounded-full ${
                                                notification.read_at === null
                                                    ? 'bg-(--color-accent-end)'
                                                    : 'bg-transparent'
                                            }`}
                                        />
                                        {notification.data.title}
                                        <span className="ml-auto flex items-center gap-1 text-xs font-normal text-(--muted)">
                                            {timeAgo(notification.created_at)}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-1 text-xs text-(--muted)">
                                        {notification.data.message}
                                        <ExternalLink className="ml-auto size-3 flex-shrink-0" />
                                    </span>
                                </button>
                            ))
                        )}
                    </div>
                    {workspaceSlug !== null && (
                        <div className="border-t border-(--surface-border) p-1">
                            <Link
                                href={
                                    NotificationController.index({
                                        workspace: workspaceSlug,
                                    }).url
                                }
                                onClick={() => setIsOpen(false)}
                                className="flex w-full items-center gap-1.5 rounded-md px-3 py-2 text-xs font-semibold text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text)"
                            >
                                <List className="size-3.5" />
                                View all notifications
                            </Link>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
