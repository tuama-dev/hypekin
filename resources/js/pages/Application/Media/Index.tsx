import { Head, InfiniteScroll, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Film, ImageIcon, Loader2, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import AuthenticatedLayout from '@/components/Layout/AuthenticatedLayout';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import MediaController from '@/actions/App/Http/Controllers/Application/MediaController';

interface MediaTypeView {
    value: string;
    label: string;
}

interface MediaStatusView {
    value: string;
    label: string;
}

interface MediaView {
    id: string;
    type: MediaTypeView;
    mime_type: string;
    size_bytes: number;
    width: number | null;
    height: number | null;
    duration_seconds: number | null;
    status: MediaStatusView;
    created_at: string | null;
    url: string;
    attached_to_post: boolean;
    posted_count: number;
    uploaded_by: string | null;
}

interface MediaPaginator {
    data: MediaView[];
    total: number;
}

interface MediaIndexPageProps {
    media: MediaPaginator;
}

const typeClasses: Record<string, string> = {
    image: 'bg-emerald-500/10 text-emerald-600',
    video: 'bg-sky-500/10 text-sky-600',
};

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes;
    let unit = -1;

    do {
        value /= 1024;
        unit += 1;
    } while (value >= 1024 && unit < units.length - 1);

    return `${value >= 100 ? value.toFixed(0) : value.toFixed(1)} ${units[unit]}`;
}

function formatDate(value: string | null): string {
    if (value === null) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleDateString(undefined, {
        dateStyle: 'medium',
    });
}

export default function MediaIndex({ media }: MediaIndexPageProps) {
    const { auth, flash } = usePage().props;
    const workspace = auth.workspace;
    const canManageMedia =
        workspace?.abilities.includes('manageMedia') ?? false;
    const [mediaToDelete, setMediaToDelete] = useState<MediaView | null>(null);

    useEffect(() => {
        if (flash.success) {
            toast.success(flash.success);
        } else if (flash.error) {
            toast.error(flash.error);
        }
    }, [flash]);

    if (workspace === null) {
        return null;
    }

    const currentWorkspace = workspace;

    function deleteMedia(item: MediaView) {
        router.delete(
            MediaController.destroy({
                workspace: currentWorkspace.slug,
                media: item.id,
            }).url,
            { preserveScroll: true },
        );
        setMediaToDelete(null);
    }

    return (
        <AuthenticatedLayout>
            <Head title="Media library" />

            <div className="flex w-full flex-col px-4 py-8 sm:px-6">
                <header className="flex flex-wrap items-baseline justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-extrabold tracking-tight text-(--text)">
                            Media library
                        </h1>
                        <p className="mt-1 text-sm text-(--muted)">
                            Files uploaded for{' '}
                            <strong className="font-semibold">
                                {currentWorkspace.name}
                            </strong>{' '}
                            · {media.total}{' '}
                            {media.total === 1 ? 'file' : 'files'}
                        </p>
                    </div>
                </header>

                <InfiniteScroll data="media">
                    {({ loadingNext }) => (
                        <div>
                            {media.data.length === 0 ? (
                                <section className="mt-6 rounded-2xl border border-dashed border-(--border) bg-(--panel) p-8 text-center">
                                    <span className="mx-auto grid size-12 place-items-center rounded-full bg-(--panel-muted) text-(--muted)">
                                        <ImageIcon
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <h2 className="mt-4 text-base font-bold text-(--text)">
                                        No media yet
                                    </h2>
                                    <p className="mx-auto mt-1 max-w-sm text-sm text-(--muted)">
                                        Upload an image or video from the post
                                        composer, then reuse it here across your
                                        posts.
                                    </p>
                                </section>
                            ) : (
                                <>
                                    <ul className="mt-6 grid grid-cols-[repeat(auto-fill,minmax(14rem,1fr))] gap-4">
                                        {media.data.map((item) => (
                                            <li
                                                key={item.id}
                                                className="group overflow-hidden rounded-2xl border border-(--border) bg-(--panel)"
                                            >
                                                <div className="relative aspect-square overflow-hidden bg-(--panel-muted)">
                                                    {item.type.value ===
                                                    'image' ? (
                                                        <img
                                                            src={item.url}
                                                            alt={`${item.type.label} media`}
                                                            loading="lazy"
                                                            className="size-full object-cover transition group-hover:scale-105"
                                                        />
                                                    ) : (
                                                        <span className="grid size-full place-items-center text-(--muted)">
                                                            <Film
                                                                className="size-10"
                                                                aria-hidden="true"
                                                            />
                                                        </span>
                                                    )}
                                                    <span
                                                        className={`absolute top-2 left-2 rounded-full px-2.5 py-1 text-xs font-semibold backdrop-blur ${
                                                            typeClasses[
                                                                item.type.value
                                                            ] ??
                                                            'bg-neutral-500/10 text-neutral-500'
                                                        }`}
                                                    >
                                                        {item.type.label}
                                                    </span>
                                                </div>

                                                <div className="flex items-center justify-between gap-3 p-3">
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-bold text-(--text)">
                                                            {formatBytes(
                                                                item.size_bytes,
                                                            )}
                                                            {item.width !==
                                                                null &&
                                                                item.height !==
                                                                    null &&
                                                                ` · ${item.width}×${item.height}`}
                                                        </p>
                                                        <p className="mt-0.5 truncate text-xs text-(--muted)">
                                                            {item.uploaded_by ??
                                                                'Unknown'}{' '}
                                                            ·{' '}
                                                            {formatDate(
                                                                item.created_at,
                                                            )}
                                                        </p>
                                                        <p className="mt-0.5 truncate text-xs text-(--muted)">
                                                            {item.attached_to_post
                                                                ? `Used in ${item.posted_count} ${
                                                                      item.posted_count ===
                                                                      1
                                                                          ? 'post'
                                                                          : 'posts'
                                                                  }`
                                                                : 'Not attached to any post'}
                                                        </p>
                                                    </div>

                                                    {canManageMedia && (
                                                        <button
                                                            type="button"
                                                            title={
                                                                item.attached_to_post
                                                                    ? 'This file is attached to a post and cannot be deleted.'
                                                                    : 'Delete this file'
                                                            }
                                                            disabled={
                                                                item.attached_to_post
                                                            }
                                                            onClick={() => {
                                                                if (
                                                                    !item.attached_to_post
                                                                ) {
                                                                    setMediaToDelete(
                                                                        item,
                                                                    );
                                                                }
                                                            }}
                                                            className="shrink-0 rounded-lg border border-(--border) p-2 text-(--muted) transition focus:outline-none enabled:hover:border-red-300 enabled:hover:bg-red-500/5 enabled:hover:text-red-600 enabled:focus:ring-4 enabled:focus:ring-red-500/20 disabled:cursor-not-allowed disabled:opacity-40"
                                                        >
                                                            <Trash2
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                        </button>
                                                    )}
                                                </div>
                                            </li>
                                        ))}
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

            <ConfirmDialog
                open={mediaToDelete !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setMediaToDelete(null);
                    }
                }}
                title="Delete this file?"
                description="The file will be removed from the media library and its storage object will be deleted. Posts that reference it are kept."
                confirmText="Delete"
                cancelText="Cancel"
                variant="danger"
                onConfirm={() => {
                    if (mediaToDelete !== null) {
                        deleteMedia(mediaToDelete);
                    }
                }}
            />
        </AuthenticatedLayout>
    );
}
