import { Head, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { CalendarClock, ImagePlus, UploadCloud, X } from 'lucide-react';
import { toast } from 'sonner';
import AuthenticatedLayout from '@/components/Layout/AuthenticatedLayout';
import PostController from '@/actions/App/Http/Controllers/Application/PostController';
import MediaController from '@/actions/App/Http/Controllers/Application/MediaController';

interface PublishableAccount {
    id: string;
    platform: { value: string; label: string };
    display_name: string;
}

interface CreatePostPageProps {
    publishableAccounts: PublishableAccount[];
}

const MAX_CAPTION_LENGTH = 3000;
const MAX_MEDIA_BYTES = 20 * 1024 * 1024;
const ACCEPTED_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
];

interface MediaIntent {
    path: string;
    upload_url: string;
    expires_at: string;
}

interface MediaComplete {
    media: {
        id: string;
        url: string;
    };
}

function csrfToken(): string {
    const row = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));
    return row ? decodeURIComponent(row.slice('XSRF-TOKEN='.length)) : '';
}

async function postJson<T>(url: string, payload: object): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        throw new Error('The media request failed. Please try again.');
    }

    return response.json() as Promise<T>;
}

function readImageSize(file: File): Promise<{ width: number; height: number }> {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();

        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve({ width: image.naturalWidth, height: image.naturalHeight });
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Unable to read the image dimensions.'));
        };
        image.src = url;
    });
}

export default function CreatePost({
    publishableAccounts,
}: CreatePostPageProps) {
    const { auth } = usePage().props;
    const workspace = auth.workspace;
    const fileInput = useRef<HTMLInputElement>(null);

    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [uploading, setUploading] = useState(false);
    const [imageMeta, setImageMeta] = useState<{
        width: number;
        height: number;
    } | null>(null);

    const form = useForm({
        caption: '',
        targets: [] as string[],
        scheduled_at: '',
        media_id: null as string | null,
    });

    if (workspace === null) {
        return null;
    }

    const currentWorkspace = workspace;
    const isInstagramSelected = publishableAccounts.some(
        (account) =>
            account.platform.value === 'instagram' &&
            form.data.targets.includes(account.id),
    );

    function toggleTarget(accountId: string) {
        const targets = form.data.targets.includes(accountId)
            ? form.data.targets.filter((id) => id !== accountId)
            : [...form.data.targets, accountId];

        form.setData('targets', targets);
        form.clearErrors('targets');
    }

    async function handleFileSelected(
        event: React.ChangeEvent<HTMLInputElement>,
    ) {
        const file = event.target.files?.[0];

        if (!file) {
            return;
        }

        if (!ACCEPTED_MIME_TYPES.includes(file.type)) {
            toast.error('Only JPEG, PNG, WebP and GIF images are supported.');
            return;
        }

        if (file.size > MAX_MEDIA_BYTES) {
            toast.error('The image must be 20MB or smaller.');
            return;
        }

        setUploading(true);
        form.clearErrors('media_id');

        try {
            const size = await readImageSize(file);
            setImageMeta(size);

            const intent = await postJson<MediaIntent>(
                MediaController.intent({ workspace: currentWorkspace.slug })
                    .url,
                {
                    mime_type: file.type,
                    size_bytes: file.size,
                },
            );

            const uploadResponse = await fetch(intent.upload_url, {
                method: 'PUT',
                headers: {
                    'Content-Type': file.type,
                },
                body: file,
            });

            if (!uploadResponse.ok) {
                throw new Error(
                    'The upload to storage failed. Please try again.',
                );
            }

            const complete = await postJson<MediaComplete>(
                MediaController.complete({ workspace: currentWorkspace.slug })
                    .url,
                {
                    path: intent.path,
                    mime_type: file.type,
                    size_bytes: file.size,
                    width: size.width,
                    height: size.height,
                },
            );

            form.setData('media_id', complete.media.id);

            const objectUrl = URL.createObjectURL(file);
            setPreviewUrl((previous) => {
                if (previous) {
                    URL.revokeObjectURL(previous);
                }
                return objectUrl;
            });
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'The upload failed. Please try again.',
            );
        } finally {
            setUploading(false);
            if (fileInput.current) {
                fileInput.current.value = '';
            }
        }
    }

    function removeImage() {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }
        setPreviewUrl(null);
        setImageMeta(null);
        form.setData('media_id', null);
    }

    function publishNow() {
        form.setData('scheduled_at', '');
        form.post(
            PostController.store({ workspace: currentWorkspace.slug }).url,
            {
                onError: () => {},
            },
        );
    }

    function schedulePost() {
        if (form.data.scheduled_at === '') {
            toast.error('Pick a date and time for the scheduled post.');
            return;
        }

        form.post(
            PostController.store({ workspace: currentWorkspace.slug }).url,
            {
                onError: () => {},
            },
        );
    }

    return (
        <AuthenticatedLayout>
            <Head title="Create post" />

            <div className="mx-auto flex w-full max-w-4xl flex-col px-4 py-8 sm:px-6">
                <header>
                    <h1 className="text-2xl font-extrabold tracking-tight text-(--text)">
                        Create post
                    </h1>
                    <p className="mt-1 text-sm text-(--muted)">
                        Compose a post for the connected accounts of{' '}
                        <strong className="font-semibold">
                            {currentWorkspace.name}
                        </strong>
                        .
                    </p>
                </header>

                <div className="mt-6 grid gap-6">
                    <section className="rounded-2xl border border-(--border) bg-(--panel) p-6">
                        <label
                            className="text-sm font-bold text-[var(--color-ink)]"
                            htmlFor="caption"
                        >
                            Caption
                            <span className="ml-1 text-rose-400">*</span>
                        </label>
                        <textarea
                            id="caption"
                            rows={6}
                            value={form.data.caption}
                            onChange={(event) => {
                                form.setData('caption', event.target.value);
                                form.clearErrors('caption');
                            }}
                            maxLength={MAX_CAPTION_LENGTH}
                            placeholder="Write something worth sharing…"
                            className="mt-2.5 w-full resize-none rounded-xl border bg-[var(--color-panel-muted)] px-4 py-3 text-sm text-[var(--color-ink)] transition outline-none placeholder:text-[var(--color-muted)]/75 focus:border-[var(--color-accent-start)] focus:bg-[var(--color-panel)] focus:ring-4 focus:ring-[color:var(--color-accent-start)]/15"
                            aria-invalid={Boolean(form.errors.caption)}
                        />
                        <div className="mt-2 flex items-center justify-between gap-4">
                            {form.errors.caption ? (
                                <p className="text-sm text-rose-400">
                                    {form.errors.caption}
                                </p>
                            ) : (
                                <span />
                            )}
                            <p className="ml-auto text-xs font-medium text-(--muted)">
                                {form.data.caption.length}/{MAX_CAPTION_LENGTH}
                            </p>
                        </div>
                    </section>

                    <section className="rounded-2xl border border-(--border) bg-(--panel) p-6">
                        <h2 className="text-base font-bold text-(--text)">
                            Publish to
                        </h2>
                        <p className="mt-1 text-sm text-(--muted)">
                            Pick the connected Facebook and Instagram accounts
                            for this post.
                        </p>

                        {form.errors.targets && (
                            <p className="mt-3 text-sm text-rose-400">
                                {form.errors.targets}
                            </p>
                        )}

                        {publishableAccounts.length === 0 ? (
                            <div className="mt-4 rounded-xl border border-dashed border-(--border) bg-(--panel-muted) p-6 text-center text-sm text-(--muted)">
                                No publishable accounts yet. Connect Facebook or
                                Instagram from the{' '}
                                <a
                                    href={`/app/${currentWorkspace.slug}/accounts`}
                                    className="font-semibold text-(--text) underline underline-offset-2"
                                >
                                    Accounts page
                                </a>
                                .
                            </div>
                        ) : (
                            <ul className="mt-4 grid gap-3">
                                {publishableAccounts.map((account) => {
                                    const isSelected =
                                        form.data.targets.includes(account.id);

                                    return (
                                        <li key={account.id}>
                                            <label
                                                className={`flex cursor-pointer items-center gap-4 rounded-xl border p-4 transition ${
                                                    isSelected
                                                        ? 'border-[var(--color-accent-start)]/50 bg-[var(--color-panel-muted)]'
                                                        : 'border-(--border)'
                                                }`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    onChange={() =>
                                                        toggleTarget(account.id)
                                                    }
                                                    className="size-4 accent-[var(--color-accent-start)]"
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-bold text-(--text)">
                                                        {account.display_name}
                                                    </p>
                                                    <p className="mt-0.5 text-xs text-(--muted)">
                                                        {account.platform.label}
                                                    </p>
                                                </div>
                                                {!isInstagramSelected &&
                                                    account.platform.value ===
                                                        'instagram' && (
                                                        <span className="shrink-0 rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-semibold text-amber-600">
                                                            Image required
                                                        </span>
                                                    )}
                                            </label>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>

                    <section className="rounded-2xl border border-(--border) bg-(--panel) p-6">
                        <h2 className="text-base font-bold text-(--text)">
                            Image
                        </h2>
                        <p className="mt-1 text-sm text-(--muted)">
                            Optional, but required for Instagram. The file
                            uploads straight to your object storage — never
                            through this app.
                        </p>

                        {previewUrl ? (
                            <div className="mt-4">
                                <div className="relative inline-block overflow-hidden rounded-xl border border-(--border)">
                                    <img
                                        src={previewUrl}
                                        alt="Selected post image preview"
                                        className="max-h-80 w-auto object-contain"
                                    />
                                    <button
                                        type="button"
                                        onClick={removeImage}
                                        className="absolute top-2 right-2 grid size-8 place-items-center rounded-lg bg-black/60 text-white backdrop-blur transition hover:bg-black/80 focus:ring-4 focus:ring-white/30 focus:outline-none"
                                        aria-label="Remove image"
                                    >
                                        <X className="size-4" />
                                    </button>
                                </div>
                                {imageMeta && (
                                    <p className="mt-2 text-xs font-medium text-(--muted)">
                                        {imageMeta.width} × {imageMeta.height}
                                    </p>
                                )}
                            </div>
                        ) : (
                            <div className="mt-4 flex items-center justify-center rounded-xl border border-dashed border-(--border) bg-(--panel-muted) p-8">
                                <div className="text-center">
                                    <span className="mx-auto grid size-12 place-items-center rounded-full bg-(--panel) text-(--muted)">
                                        <ImagePlus
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <p className="mt-3 text-sm font-semibold text-(--text)">
                                        {uploading
                                            ? 'Uploading to storage…'
                                            : 'Drop an image here'}
                                    </p>
                                    <p className="mt-1 text-xs text-(--muted)">
                                        JPEG, PNG, WebP or GIF · up to 20MB
                                    </p>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            fileInput.current?.click()
                                        }
                                        disabled={uploading}
                                        className="mt-4 inline-flex items-center gap-2 rounded-lg border border-(--border) px-5 py-2.5 text-sm font-semibold text-(--muted) transition hover:bg-(--panel) hover:text-(--text) focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <UploadCloud
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Choose image
                                    </button>
                                </div>
                            </div>
                        )}

                        <input
                            ref={fileInput}
                            type="file"
                            accept={ACCEPTED_MIME_TYPES.join(',')}
                            onChange={handleFileSelected}
                            className="hidden"
                        />

                        {form.errors.media_id && (
                            <p className="mt-3 text-sm text-rose-400">
                                {form.errors.media_id}
                            </p>
                        )}
                    </section>

                    <section className="rounded-2xl border border-(--border) bg-(--panel) p-6">
                        <h2 className="text-base font-bold text-(--text)">
                            Schedule
                        </h2>
                        <p className="mt-1 text-sm text-(--muted)">
                            Publish immediately, or pick a future date and time.
                        </p>

                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <label
                                className="grid gap-2.5"
                                htmlFor="scheduled_at"
                            >
                                <span className="text-sm font-bold text-[var(--color-ink)]">
                                    Date and time
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
                                        id="scheduled_at"
                                        value={form.data.scheduled_at}
                                        onChange={(event) =>
                                            form.setData(
                                                'scheduled_at',
                                                event.target.value,
                                            )
                                        }
                                        className="h-13 w-full rounded-xl border bg-(--panel-muted) px-4 pl-11 text-sm text-(--text) transition outline-none placeholder:text-(--muted)/75 focus:border-(--color-accent-start) focus:bg-(--panel) focus:ring-4 focus:ring-[color:var(--color-accent-start)]/15"
                                    />
                                </div>
                            </label>

                            <div className="flex items-end gap-3">
                                <button
                                    type="button"
                                    onClick={publishNow}
                                    disabled={form.processing || uploading}
                                    className="inline-flex flex-1 items-center justify-center rounded-xl bg-linear-to-r from-[var(--color-accent-start)] to-[var(--color-accent-end)] px-5 py-3 text-sm font-bold text-[var(--color-accent-ink)] shadow-md shadow-[color:var(--color-accent-end)]/20 transition hover:-translate-y-0.5 hover:brightness-105 focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {form.processing
                                        ? 'Publishing…'
                                        : 'Publish now'}
                                </button>
                                <button
                                    type="button"
                                    onClick={schedulePost}
                                    disabled={form.processing || uploading}
                                    className="inline-flex flex-1 items-center justify-center rounded-xl border border-(--border) px-5 py-3 text-sm font-bold text-(--text) transition hover:bg-(--panel-muted) focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {form.processing
                                        ? 'Scheduling…'
                                        : 'Schedule'}
                                </button>
                            </div>
                        </div>

                        {form.errors.scheduled_at && (
                            <p className="mt-3 text-sm text-rose-400">
                                {form.errors.scheduled_at}
                            </p>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
