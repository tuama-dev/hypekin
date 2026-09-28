import { useForm } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';
import { CalendarClock, X } from 'lucide-react';
import PostController from '@/actions/App/Http/Controllers/Application/PostController';

export interface ReschedulablePost {
    id: string;
    caption: string;
    scheduled_at: string | null;
}

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

function toDatetimeLocal(date: Date): string {
    return `${pad(date.getFullYear())}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function Modal({
    title,
    onClose,
    children,
}: {
    title: string;
    onClose: () => void;
    children: ReactNode;
}) {
    useEffect(() => {
        document.body.style.overflow = 'hidden';

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onClose();
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.body.style.overflow = '';
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [onClose]);

    return (
        <div
            className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
        >
            <div className="w-full max-w-lg rounded-2xl border border-(--border) bg-(--panel) p-6 shadow-2xl">
                <div className="flex items-start justify-between gap-4">
                    <h2 className="text-base font-bold text-(--text)">
                        {title}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="grid size-8 shrink-0 place-items-center rounded-lg text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text) focus:ring-4 focus:ring-[color:var(--color-line)]/40 focus:outline-none"
                        aria-label="Close dialog"
                    >
                        <X className="size-4" />
                    </button>
                </div>
                <div className="mt-5">{children}</div>
            </div>
        </div>
    );
}

/**
 * Move a scheduled post to a new date and time, or release it to publish
 * immediately by clearing the schedule.
 */
export function RescheduleModal({
    workspaceSlug,
    post,
    onClose,
}: {
    workspaceSlug: string;
    post: ReschedulablePost;
    onClose: () => void;
}) {
    const form = useForm({
        scheduled_at:
            post.scheduled_at !== null
                ? toDatetimeLocal(new Date(post.scheduled_at))
                : '',
    });

    function send(scheduledAt: string) {
        form.setData('scheduled_at', scheduledAt);
        form.patch(
            PostController.reschedule({
                workspace: workspaceSlug,
                post: post.id,
            }).url,
            { onSuccess: () => onClose() },
        );
    }

    return (
        <Modal
            title={`Move “${post.caption || 'Untitled post'}”`}
            onClose={onClose}
        >
            <div className="grid gap-5">
                <label className="grid gap-2.5" htmlFor="reschedule-at">
                    <span className="text-sm font-bold text-[var(--color-ink)]">
                        New date and time
                    </span>
                    <div className="relative">
                        <span
                            className="pointer-events-none absolute inset-y-0 left-4 flex items-center text-(--muted)"
                            aria-hidden="true"
                        >
                            <CalendarClock className="size-4" />
                        </span>
                        <input
                            type="datetime-local"
                            id="reschedule-at"
                            value={form.data.scheduled_at}
                            onChange={(event) => {
                                form.setData(
                                    'scheduled_at',
                                    event.target.value,
                                );
                                form.clearErrors('scheduled_at');
                            }}
                            className="h-13 w-full rounded-xl border bg-(--panel-muted) px-4 pl-11 text-sm text-(--text) transition outline-none placeholder:text-(--muted)/75 focus:border-(--color-accent-start) focus:bg-(--panel) focus:ring-4 focus:ring-[color:var(--color-accent-start)]/15"
                        />
                    </div>
                    {form.errors.scheduled_at && (
                        <p className="text-sm text-rose-400">
                            {form.errors.scheduled_at}
                        </p>
                    )}
                </label>

                <div className="flex gap-3">
                    <button
                        type="button"
                        onClick={() => send('')}
                        disabled={form.processing}
                        className="inline-flex flex-1 items-center justify-center rounded-xl border border-(--border) px-5 py-3 text-sm font-semibold text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text) focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {form.processing ? 'Saving…' : 'Publish now'}
                    </button>
                    <button
                        type="button"
                        onClick={() => send(form.data.scheduled_at)}
                        disabled={form.processing}
                        className="inline-flex flex-1 items-center justify-center rounded-xl bg-linear-to-r from-[var(--color-accent-start)] to-[var(--color-accent-end)] px-5 py-3 text-sm font-bold text-[var(--color-accent-ink)] shadow-md shadow-[color:var(--color-accent-end)]/20 transition hover:-translate-y-0.5 hover:brightness-105 focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {form.processing ? 'Saving…' : 'Move'}
                    </button>
                </div>
            </div>
        </Modal>
    );
}
