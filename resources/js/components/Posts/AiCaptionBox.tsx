import { useState } from 'react';
import { LoaderCircle, Sparkles, Wand2, X } from 'lucide-react';
import { toast } from 'sonner';
import AiCaptionController from '@/actions/App/Http/Controllers/Application/AiCaptionController';
import { cn } from '@/lib/utils';

interface AiCaptionResult {
    caption: string;
    title: string | null;
    hashtags: Record<string, string[]>;
}

interface AiCaptionBoxProps {
    workspaceSlug: string;
    platforms: string[];
    caption: string;
    title: string;
    maxCaptionLength: number;
    maxTitleLength: number;
    onCaption: (caption: string) => void;
    onTitle: (title: string) => void;
    onDismiss: () => void;
}

const TONES = [
    { value: 'casual', label: 'Casual' },
    { value: 'professional', label: 'Professional' },
    { value: 'engaging', label: 'Engaging' },
    { value: 'funny', label: 'Funny' },
    { value: 'informative', label: 'Informative' },
];

const PLATFORM_LABELS: Record<string, string> = {
    facebook: 'Facebook',
    instagram: 'Instagram',
    linkedin: 'LinkedIn',
    tiktok: 'TikTok',
};

function csrfToken(): string {
    const row = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));
    return row ? decodeURIComponent(row.slice('XSRF-TOKEN='.length)) : '';
}

export function AiCaptionBox({
    workspaceSlug,
    platforms,
    caption,
    maxCaptionLength,
    maxTitleLength,
    onCaption,
    onTitle,
    onDismiss,
}: AiCaptionBoxProps) {
    const [brief, setBrief] = useState('');
    const [tone, setTone] = useState<string | null>(null);
    const [generating, setGenerating] = useState(false);
    const [result, setResult] = useState<AiCaptionResult | null>(null);
    const [selectedPlatform, setSelectedPlatform] = useState<string | null>(
        null,
    );

    const availablePlatforms = platforms.filter(
        (platform) => result?.hashtags[platform] !== undefined,
    );
    const shownPlatforms =
        availablePlatforms.length > 0 ? availablePlatforms : platforms;
    const activePlatform =
        selectedPlatform !== null && shownPlatforms.includes(selectedPlatform)
            ? selectedPlatform
            : (shownPlatforms[0] ?? null);

    async function generate() {
        if (brief.trim() === '' || generating) {
            return;
        }

        setGenerating(true);

        try {
            const response = await fetch(
                AiCaptionController.generate({
                    workspace: workspaceSlug,
                }).url,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({
                        brief: brief.trim(),
                        tone: tone ?? undefined,
                        platforms,
                    }),
                },
            );

            const body = (await response.json().catch(() => ({}))) as {
                error?: string;
            } & AiCaptionResult;

            if (!response.ok) {
                if (response.status === 422 || response.status === 503) {
                    toast.error(
                        body.error ?? 'Could not generate the caption.',
                    );
                } else {
                    toast.error('Could not generate the caption. Try again.');
                }
                return;
            }

            setResult({
                caption: body.caption,
                title: body.title ?? null,
                hashtags: body.hashtags ?? {},
            });
            setSelectedPlatform(null);
        } catch {
            toast.error('Could not generate the caption. Try again.');
        } finally {
            setGenerating(false);
        }
    }

    function insertHashtags() {
        if (!result || activePlatform === null) {
            return;
        }

        const hashtags = result.hashtags[activePlatform] ?? [];
        const joined = hashtags.join(' ');
        const separator = caption.trim() === '' ? '' : '\n\n';
        const next = caption + separator + joined;

        onCaption(next.slice(0, maxCaptionLength));
        toast.success(`Hashtags added for ${PLATFORM_LABELS[activePlatform]}.`);
    }

    function useCaption() {
        if (!result) {
            return;
        }

        onCaption(result.caption.slice(0, maxCaptionLength));
        toast.success('Caption applied.');
    }

    function useTitle() {
        if (!result?.title) {
            return;
        }

        onTitle(result.title.slice(0, maxTitleLength));
        toast.success('Title applied.');
    }

    return (
        <div className="rounded-2xl border border-(--border) bg-(--panel) p-6">
            <div className="flex items-center justify-between gap-4">
                <div className="flex items-center gap-2">
                    <Sparkles
                        className="size-4 text-(--color-accent-start)"
                        aria-hidden="true"
                    />
                    <h2 className="text-base font-bold text-(--text)">
                        AI caption
                    </h2>
                </div>
                <button
                    type="button"
                    onClick={onDismiss}
                    className="grid size-8 place-items-center rounded-lg text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text)"
                    aria-label="Dismiss AI caption helper"
                >
                    <X className="size-4" />
                </button>
            </div>
            <p className="mt-1 text-sm text-(--muted)">
                Describe the post and generate a caption with per-platform
                hashtags you can apply to the draft.
            </p>

            <div className="mt-4 grid gap-3">
                <textarea
                    value={brief}
                    onChange={(event) => setBrief(event.target.value)}
                    rows={3}
                    maxLength={500}
                    placeholder="e.g. A behind-the-scenes look at how we hand-finish every walnut table…"
                    className="w-full resize-none rounded-xl border bg-(--panel-muted) px-4 py-3 text-sm text-(--text) transition outline-none placeholder:text-(--muted)/75 focus:border-(--color-accent-start) focus:bg-(--panel) focus:ring-4 focus:ring-[color:var(--color-accent-start)]/15"
                />

                <div className="flex flex-wrap items-center gap-1.5">
                    {TONES.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            onClick={() =>
                                setTone((current) =>
                                    current === option.value
                                        ? null
                                        : option.value,
                                )
                            }
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs font-semibold transition focus:ring-4 focus:outline-none',
                                tone === option.value
                                    ? 'border-(--color-accent-start) bg-(--color-accent-start)/10 text-(--color-accent-start)'
                                    : 'border-(--border) text-(--muted) hover:bg-(--panel-muted) hover:text-(--text)',
                            )}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>

                <button
                    type="button"
                    onClick={generate}
                    disabled={brief.trim() === '' || generating}
                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-linear-to-r from-[var(--color-accent-start)] to-[var(--color-accent-end)] px-5 py-2.5 text-sm font-bold text-[var(--color-accent-ink)] shadow-md shadow-[color:var(--color-accent-end)]/20 transition hover:brightness-105 focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {generating ? (
                        <LoaderCircle
                            className="size-4 animate-spin"
                            aria-hidden="true"
                        />
                    ) : (
                        <Wand2 className="size-4" aria-hidden="true" />
                    )}
                    {generating ? 'Generating…' : 'Generate caption'}
                </button>
            </div>

            {result && (
                <div className="mt-5 space-y-4 rounded-xl border border-(--border) bg-(--panel-muted) p-4">
                    <div>
                        <p className="text-xs font-bold tracking-wide text-(--muted) uppercase">
                            Caption draft
                        </p>
                        <p className="mt-1.5 text-sm leading-relaxed whitespace-pre-line text-(--text)">
                            {result.caption}
                        </p>
                        <button
                            type="button"
                            onClick={useCaption}
                            className="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-(--border) px-3 py-1.5 text-xs font-semibold text-(--text) transition hover:bg-(--panel) focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none"
                        >
                            Use caption
                        </button>
                    </div>

                    {result.title !== null && (
                        <div>
                            <p className="text-xs font-bold tracking-wide text-(--muted) uppercase">
                                TikTok title
                            </p>
                            <p className="mt-1.5 text-sm text-(--text)">
                                {result.title}
                            </p>
                            <button
                                type="button"
                                onClick={useTitle}
                                className="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-(--border) px-3 py-1.5 text-xs font-semibold text-(--text) transition hover:bg-(--panel) focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none"
                            >
                                Use title
                            </button>
                        </div>
                    )}

                    {shownPlatforms.length > 0 &&
                        result.hashtags[activePlatform ?? ''] !== undefined && (
                            <div>
                                <div className="flex flex-wrap items-center gap-1.5">
                                    {shownPlatforms.map((platform) => (
                                        <button
                                            key={platform}
                                            type="button"
                                            onClick={() =>
                                                setSelectedPlatform(platform)
                                            }
                                            className={cn(
                                                'rounded-full border px-3 py-1 text-xs font-semibold transition focus:ring-4 focus:outline-none',
                                                activePlatform === platform
                                                    ? 'border-(--color-accent-start) bg-(--color-accent-start)/10 text-(--color-accent-start)'
                                                    : 'border-(--border) text-(--muted) hover:bg-(--panel) hover:text-(--text)',
                                            )}
                                        >
                                            {PLATFORM_LABELS[platform] ??
                                                platform}
                                        </button>
                                    ))}
                                </div>
                                <div className="mt-2.5 flex flex-wrap gap-1.5">
                                    {(
                                        result.hashtags[activePlatform ?? ''] ??
                                        []
                                    ).map((hashtag) => (
                                        <span
                                            key={hashtag}
                                            className="rounded-full bg-(--panel) px-2 py-0.5 text-xs font-semibold text-(--color-accent-start)"
                                        >
                                            {hashtag}
                                        </span>
                                    ))}
                                </div>
                                <button
                                    type="button"
                                    onClick={insertHashtags}
                                    className="mt-2.5 inline-flex items-center gap-1.5 rounded-lg border border-(--border) px-3 py-1.5 text-xs font-semibold text-(--text) transition hover:bg-(--panel) focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none"
                                >
                                    Insert hashtags
                                </button>
                            </div>
                        )}
                </div>
            )}
        </div>
    );
}
