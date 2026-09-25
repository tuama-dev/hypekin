import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    Clock,
    FileText,
    Plus,
    Send,
} from 'lucide-react';
import AuthenticatedLayout from '@/components/Layout/AuthenticatedLayout';

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

interface PostView {
    id: string;
    status: { value: string; label: string };
    scheduled_at: string | null;
    created_at: string | null;
    targets: PostTargetView[];
    media: PostMediaView[];
}

interface PostsIndexPageProps {
    posts: PostView[];
}

const statusClasses: Record<string, string> = {
    scheduled: 'bg-sky-500/10 text-sky-600',
    publishing: 'bg-sky-500/10 text-sky-600',
    published: 'bg-emerald-500/10 text-emerald-600',
    failed: 'bg-rose-500/10 text-rose-600',
    draft: 'bg-neutral-500/10 text-neutral-500',
    canceled: 'bg-neutral-500/10 text-neutral-500',
};

const targetStatusClasses: Record<string, string> = {
    pending: 'bg-neutral-500/10 text-neutral-500',
    queued: 'bg-sky-500/10 text-sky-600',
    published: 'bg-emerald-500/10 text-emerald-600',
    failed: 'bg-rose-500/10 text-rose-600',
};

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

export default function PostsIndex({ posts }: PostsIndexPageProps) {
    const { auth } = usePage().props;
    const workspace = auth.workspace;

    if (workspace === null) {
        return null;
    }

    const currentWorkspace = workspace;

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
                        href={`/app/${currentWorkspace.slug}/posts/create`}
                        className="inline-flex items-center gap-2 rounded-lg bg-linear-to-r from-[var(--color-accent-start)] to-[var(--color-accent-end)] px-5 py-2.5 text-sm font-bold text-[var(--color-accent-ink)] shadow-md shadow-[color:var(--color-accent-end)]/20 transition hover:-translate-y-0.5 hover:brightness-105 focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none"
                    >
                        <Plus className="size-4" aria-hidden="true" />
                        New post
                    </Link>
                </header>

                {posts.length === 0 ? (
                    <section className="mt-6 rounded-2xl border border-dashed border-(--border) bg-(--panel) p-8 text-center">
                        <span className="mx-auto grid size-12 place-items-center rounded-full bg-(--panel-muted) text-(--muted)">
                            <Send className="size-5" aria-hidden="true" />
                        </span>
                        <h2 className="mt-4 text-base font-bold text-(--text)">
                            No posts yet
                        </h2>
                        <p className="mx-auto mt-1 max-w-sm text-sm text-(--muted)">
                            Create your first post with a built-in composer,
                            then it publishes to your connected Facebook and
                            Instagram accounts.
                        </p>
                    </section>
                ) : (
                    <ul className="mt-6 grid gap-4">
                        {posts.map((post) => (
                            <li
                                key={post.id}
                                className="rounded-2xl border border-(--border) bg-(--panel) p-5"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div className="flex min-w-0 items-center gap-4">
                                        {post.media[0] ? (
                                            <img
                                                src={post.media[0].url}
                                                alt="Post media thumbnail"
                                                className="grid size-14 shrink-0 place-items-center rounded-xl border border-(--border) object-cover"
                                            />
                                        ) : (
                                            <span className="grid size-14 shrink-0 place-items-center rounded-xl bg-(--panel-muted) text-(--muted)">
                                                <FileText
                                                    className="size-5"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                        )}
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span
                                                    className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
                                                        statusClasses[
                                                            post.status.value
                                                        ] ??
                                                        'bg-neutral-500/10 text-neutral-500'
                                                    }`}
                                                >
                                                    {post.status.label}
                                                </span>
                                                {post.scheduled_at !== null && (
                                                    <span className="inline-flex items-center gap-1.5 text-xs font-medium text-(--muted)">
                                                        <Clock
                                                            className="size-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        {formatDate(
                                                            post.scheduled_at,
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                            <p className="mt-1.5 text-xs text-(--muted)">
                                                Created{' '}
                                                {formatDate(post.created_at)}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div className="mt-4 flex flex-wrap items-center gap-2">
                                    {post.targets.map((target) => (
                                        <span
                                            key={target.id}
                                            title={
                                                target.error_message !== null
                                                    ? target.error_message
                                                    : undefined
                                            }
                                            className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold ${
                                                targetStatusClasses[
                                                    target.status.value
                                                ] ??
                                                'bg-neutral-500/10 text-neutral-500'
                                            }`}
                                        >
                                            {target.status.value ===
                                                'published' && (
                                                <CheckCircle2
                                                    className="size-3.5"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {target.status.value ===
                                                'failed' && (
                                                <AlertCircle
                                                    className="size-3.5"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {target.display_name} ·{' '}
                                            {target.platform.label}
                                        </span>
                                    ))}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
