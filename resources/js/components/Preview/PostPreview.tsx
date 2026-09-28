import type { ComponentType, ReactElement, SVGProps } from 'react';
import {
    Bookmark,
    Eye,
    Heart,
    MessageCircle,
    MoreHorizontal,
    Music2,
    Repeat2,
    Send,
    Share2,
    ThumbsUp,
} from 'lucide-react';

export interface PreviewRenderProps {
    caption: string;
    title?: string;
    imageUrl: string | null;
    authorName: string;
    platformLabel: string;
    requiresMedia: boolean;
}

export interface PlatformPreviewDefinition {
    id: string;
    label: string;
    icon: ComponentType<SVGProps<SVGSVGElement>>;
    requiresMedia: boolean;
    maxCharacters: number;
    accentClass: string;
    render: (props: PreviewRenderProps) => ReactElement;
}

function FacebookIcon(props: SVGProps<SVGSVGElement>): ReactElement {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
            {...props}
        >
            <path d="M24 12.073C24 5.446 18.627.073 12 .073S0 5.446 0 12.073c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
        </svg>
    );
}

function InstagramIcon(props: SVGProps<SVGSVGElement>): ReactElement {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
            {...props}
        >
            <path d="M12 2.2c3.2 0 3.6 0 4.9.1 1.2.1 1.8.2 2.2.4.6.2 1 .5 1.4.9.4.4.7.8.9 1.4.2.4.4 1 .4 2.2.1 1.3.1 1.7.1 4.9s0 3.6-.1 4.9c-.1 1.2-.2 1.8-.4 2.2-.2.6-.5 1-.9 1.4-.4.4-.8.7-1.4.9-.4.2-1 .4-2.2.4-1.3.1-1.7.1-4.9.1s-3.6 0-4.9-.1c-1.2-.1-1.8-.2-2.2-.4-.6-.2-1-.5-1.4-.9-.4-.4-.7-.8-.9-1.4-.2-.4-.4-1-.4-2.2-.1-1.3-.1-1.7-.1-4.9s0-3.6.1-4.9c.1-1.2.2-1.8.4-2.2.2-.6.5-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.2 1-.4 2.2-.4 1.3-.1 1.7-.1 4.9-.1zm0 1.5c-3.2 0-3.5 0-4.8.1-1.1 0-1.7.2-2.1.4-.5.2-.9.4-1.2.8-.4.4-.6.7-.8 1.2-.2.4-.4 1-.4 2.1-.1 1.3-.1 1.6-.1 4.8s0 3.5.1 4.8c0 1.1.2 1.7.4 2.1.2.5.4.9.8 1.2.4.4.7.6 1.2.8.4.2 1 .4 2.1.4 1.3.1 1.6.1 4.8.1s3.5 0 4.8-.1c1.1 0 1.7-.2 2.1-.4.5-.2.9-.4 1.2-.8.4-.4.6-.7.8-1.2.2-.4.4-1 .4-2.1.1-1.3.1-1.6.1-4.8s0-3.5-.1-4.8c0-1.1-.2-1.7-.4-2.1-.2-.5-.4-.9-.8-1.2-.4-.4-.7-.6-1.2-.8-.4-.2-1-.4-2.1-.4-1.2-.1-1.6-.1-4.8-.1zm0 2.8a4.7 4.7 0 1 1 0 9.4 4.7 4.7 0 0 1 0-9.4zm0 7.8a3.1 3.1 0 1 0 0-6.2 3.1 3.1 0 0 0 0 6.2zm5.3-8a1.1 1.1 0 1 1-2.2 0 1.1 1.1 0 0 1 2.2 0z" />
        </svg>
    );
}

function LinkedInIcon(props: SVGProps<SVGSVGElement>): ReactElement {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
            {...props}
        >
            <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 1 1 0-4.125 2.062 2.062 0 0 1 0 4.125zM7.119 20.452H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.225 0z" />
        </svg>
    );
}

function TikTokIcon(props: SVGProps<SVGSVGElement>): ReactElement {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
            {...props}
        >
            <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.51 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.88c.28 0 .55.05.8.13V9.05a6.34 6.34 0 0 0-.8-.05 6.34 6.34 0 1 0 6.34 6.34V9.51A8.16 8.16 0 0 0 19.59 6.69z" />
        </svg>
    );
}

type CaptionPart = { text: string; kind: 'text' | 'hashtag' | 'mention' };

function tokenizeCaption(caption: string): CaptionPart[] {
    const parts: CaptionPart[] = [];
    const tokenPattern = /(#[\p{L}\p{N}_]+|@[\p{L}\p{N}_.]+)/gu;
    let cursor = 0;

    for (const match of caption.matchAll(tokenPattern)) {
        const index = match.index ?? 0;
        const token = match[0];

        if (index > cursor) {
            parts.push({ text: caption.slice(cursor, index), kind: 'text' });
        }

        parts.push({
            text: token,
            kind: token.startsWith('#') ? 'hashtag' : 'mention',
        });
        cursor = index + token.length;
    }

    if (cursor < caption.length) {
        parts.push({ text: caption.slice(cursor), kind: 'text' });
    }

    return parts;
}

function CaptionText({
    caption,
    accentClass,
}: {
    caption: string;
    accentClass: string;
}) {
    return (
        <p className="text-sm leading-relaxed break-words whitespace-pre-wrap">
            {tokenizeCaption(caption).map((part, index) =>
                part.kind === 'text' ? (
                    <span key={index}>{part.text}</span>
                ) : (
                    <span
                        key={index}
                        className={`font-semibold ${accentClass}`}
                    >
                        {part.text}
                    </span>
                ),
            )}
        </p>
    );
}

function AuthorAvatar({ name }: { name: string }) {
    const initial = name.trim().slice(0, 1).toUpperCase();

    return (
        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-linear-to-br from-[var(--color-accent-start)] to-[var(--color-accent-end)] text-sm font-bold text-[var(--color-accent-ink)]">
            {initial || '?'}
        </span>
    );
}

function MediaNotice({ platformLabel }: { platformLabel: string }) {
    return (
        <div className="flex aspect-square items-center justify-center rounded-xl border border-dashed border-(--border) bg-(--panel-muted) p-6 text-center text-sm font-semibold text-(--muted)">
            {platformLabel} requires an image to publish
        </div>
    );
}

function FacebookPreview(props: PreviewRenderProps) {
    return (
        <div className="rounded-xl border border-(--border) bg-(--panel) p-5">
            <div className="flex items-center gap-3">
                <AuthorAvatar name={props.authorName} />
                <div className="min-w-0">
                    <p className="truncate text-sm font-bold text-(--text)">
                        {props.authorName}
                    </p>
                    <p className="text-xs text-(--muted)">Just now · 🌐</p>
                </div>
            </div>

            {props.caption !== '' && (
                <div className="mt-3">
                    <CaptionText
                        caption={props.caption}
                        accentClass="text-[#1877F2]"
                    />
                </div>
            )}

            {props.imageUrl !== null && (
                <img
                    src={props.imageUrl}
                    alt=""
                    className="mt-3 max-h-72 w-full rounded-lg border border-(--border) object-contain"
                />
            )}

            <div className="mt-4 flex items-center gap-6 border-t border-(--border) pt-3 text-(--muted)">
                <span className="flex items-center gap-1.5 text-xs font-semibold">
                    <ThumbsUp className="size-4" aria-hidden="true" />
                    Like
                </span>
                <span className="flex items-center gap-1.5 text-xs font-semibold">
                    <MessageCircle className="size-4" aria-hidden="true" />
                    Comment
                </span>
                <span className="flex items-center gap-1.5 text-xs font-semibold">
                    <Share2 className="size-4" aria-hidden="true" />
                    Share
                </span>
            </div>
        </div>
    );
}

function InstagramPreview(props: PreviewRenderProps) {
    const author = props.authorName || '@your_account';

    return (
        <div className="rounded-xl border border-(--border) bg-(--panel) p-5">
            <div className="flex items-center gap-3">
                <AuthorAvatar name={author} />
                <p className="min-w-0 flex-1 truncate text-sm font-bold text-(--text)">
                    {author}
                </p>
                <MoreHorizontal
                    className="size-5 text-(--muted)"
                    aria-hidden="true"
                />
            </div>

            {props.imageUrl !== null ? (
                <img
                    src={props.imageUrl}
                    alt=""
                    className="mt-3 aspect-square w-full rounded-lg border border-(--border) object-cover"
                />
            ) : (
                <div className="mt-3">
                    <MediaNotice platformLabel={props.platformLabel} />
                </div>
            )}

            <div className="mt-3 flex items-center gap-4 text-(--muted)">
                <Heart className="size-5" aria-hidden="true" />
                <MessageCircle className="size-5" aria-hidden="true" />
                <Send className="size-5" aria-hidden="true" />
                <Bookmark className="ml-auto size-5" aria-hidden="true" />
            </div>

            <p className="mt-2 text-xs font-bold text-(--text)">12 likes</p>

            {props.caption !== '' && (
                <div className="mt-1 flex flex-wrap gap-x-1">
                    <span className="text-sm font-bold text-(--text)">
                        {author}
                    </span>
                    <CaptionText caption={props.caption} accentClass="" />
                </div>
            )}
        </div>
    );
}

function LinkedInPreview(props: PreviewRenderProps) {
    return (
        <div className="rounded-xl border border-(--border) bg-(--panel) p-5">
            <div className="flex items-center gap-3">
                <AuthorAvatar name={props.authorName} />
                <div className="min-w-0">
                    <p className="truncate text-sm font-bold text-(--text)">
                        {props.authorName}
                    </p>
                    <p className="truncate text-xs text-(--muted)">
                        1st · Just now · 🌐
                    </p>
                </div>
            </div>

            <div className="mt-3">
                <CaptionText
                    caption={props.caption}
                    accentClass="text-[#0A66C2]"
                />
            </div>

            {props.imageUrl !== null && (
                <img
                    src={props.imageUrl}
                    alt=""
                    className="mt-3 max-h-72 w-full rounded-lg border border-(--border) object-contain"
                />
            )}

            <div className="mt-4 flex items-center gap-4 border-t border-(--border) pt-3 text-(--muted)">
                <span className="flex items-center gap-1.5 text-xs font-semibold">
                    <ThumbsUp className="size-4" aria-hidden="true" />
                    Like
                </span>
                <span className="flex items-center gap-1.5 text-xs font-semibold">
                    <MessageCircle className="size-4" aria-hidden="true" />
                    Comment
                </span>
                <span className="flex items-center gap-1.5 text-xs font-semibold">
                    <Repeat2 className="size-4" aria-hidden="true" />
                    Repost
                </span>
                <span className="flex items-center gap-1.5 text-xs font-semibold">
                    <Send className="size-4" aria-hidden="true" />
                    Send
                </span>
            </div>
        </div>
    );
}

function TikTokPreview(props: PreviewRenderProps) {
    const author = props.authorName || '@your_account';

    return (
        <div className="flex justify-center">
            <div className="relative aspect-[9/16] w-full max-w-[15rem] overflow-hidden rounded-xl bg-neutral-950">
                {props.imageUrl !== null ? (
                    <img
                        src={props.imageUrl}
                        alt=""
                        className="absolute inset-0 h-full w-full object-cover opacity-90"
                    />
                ) : (
                    <div className="absolute inset-0 flex items-center justify-center p-6 text-center text-sm font-semibold text-neutral-400">
                        {props.platformLabel} requires media
                    </div>
                )}

                <div className="absolute right-3 bottom-24 flex flex-col items-center gap-4 text-white">
                    <span className="flex flex-col items-center gap-0.5">
                        <Heart className="size-7" aria-hidden="true" />
                        <span className="text-[10px] font-semibold">1.2K</span>
                    </span>
                    <span className="flex flex-col items-center gap-0.5">
                        <MessageCircle className="size-7" aria-hidden="true" />
                        <span className="text-[10px] font-semibold">248</span>
                    </span>
                    <Bookmark className="size-7" aria-hidden="true" />
                    <Share2 className="size-7" aria-hidden="true" />
                </div>

                <div className="absolute inset-x-0 bottom-0 bg-linear-to-t from-black/80 to-transparent p-4 pt-12">
                    <p className="truncate text-sm font-bold text-white">
                        {author}
                    </p>
                    {props.title !== undefined && props.title !== '' && (
                        <p className="mt-1 line-clamp-2 text-xs font-semibold text-[#25F4EE]">
                            {props.title}
                        </p>
                    )}
                    {props.caption !== '' && (
                        <div className="mt-0.5">
                            <CaptionText
                                caption={props.caption}
                                accentClass="text-[#25F4EE]"
                            />
                        </div>
                    )}
                    <p className="mt-2 flex items-center gap-1.5 text-xs font-medium text-white">
                        <Music2
                            className="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span className="truncate">
                            original sound — {author.replace('@', '')}
                        </span>
                    </p>
                </div>
            </div>
        </div>
    );
}

function GenericPlatformIcon(props: SVGProps<SVGSVGElement>): ReactElement {
    return <Share2 {...props} />;
}

function FallbackPreview(props: PreviewRenderProps) {
    return (
        <div className="rounded-xl border border-(--border) bg-(--panel) p-5">
            <div className="flex items-center gap-3">
                <AuthorAvatar name={props.authorName} />
                <div className="min-w-0">
                    <p className="truncate text-sm font-bold text-(--text)">
                        {props.platformLabel}
                    </p>
                    <p className="text-xs text-(--muted)">Connected account</p>
                </div>
            </div>

            <div className="mt-3">
                <CaptionText caption={props.caption} accentClass="" />
            </div>

            {props.imageUrl !== null && (
                <img
                    src={props.imageUrl}
                    alt=""
                    className="mt-3 max-h-72 w-full rounded-lg border border-(--border) object-contain"
                />
            )}
        </div>
    );
}

export const platformPreviewDefinitions: PlatformPreviewDefinition[] = [
    {
        id: 'facebook',
        label: 'Facebook',
        icon: FacebookIcon,
        requiresMedia: false,
        maxCharacters: 3000,
        accentClass: 'text-[#1877F2]',
        render: FacebookPreview,
    },
    {
        id: 'instagram',
        label: 'Instagram',
        icon: InstagramIcon,
        requiresMedia: true,
        maxCharacters: 2200,
        accentClass: '',
        render: InstagramPreview,
    },
    {
        id: 'linkedin',
        label: 'LinkedIn',
        icon: LinkedInIcon,
        requiresMedia: false,
        maxCharacters: 3000,
        accentClass: 'text-[#0A66C2]',
        render: LinkedInPreview,
    },
    {
        id: 'tiktok',
        label: 'TikTok',
        icon: TikTokIcon,
        requiresMedia: true,
        maxCharacters: 2200,
        accentClass: 'text-[#25F4EE]',
        render: TikTokPreview,
    },
];

export function createFallbackDefinition(platform: {
    value: string;
    label: string;
}): PlatformPreviewDefinition {
    return {
        id: platform.value,
        label: platform.label,
        icon: GenericPlatformIcon,
        requiresMedia: false,
        maxCharacters: 3000,
        accentClass: '',
        render: FallbackPreview,
    };
}

interface PostPreviewPaneProps {
    definitions: PlatformPreviewDefinition[];
    activePlatformId: string;
    onActivePlatformChange: (platformId: string) => void;
    caption: string;
    title?: string;
    imageUrl: string | null;
    authorName: string;
    zeroSelected: boolean;
}

export default function PostPreviewPane({
    definitions,
    activePlatformId,
    onActivePlatformChange,
    caption,
    title,
    imageUrl,
    authorName,
    zeroSelected,
}: PostPreviewPaneProps) {
    const activeDefinition =
        definitions.find((definition) => definition.id === activePlatformId) ??
        definitions[0];

    const isEmpty =
        caption.trim() === '' && title?.trim() === '' && imageUrl === null;

    return (
        <section
            aria-label="Post preview"
            className="rounded-2xl border border-(--border) bg-(--panel) p-5"
        >
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-base font-bold text-(--text)">
                    Live preview
                </h2>
            </div>

            {zeroSelected ? (
                <div className="mt-5 flex flex-col items-center justify-center rounded-xl border border-dashed border-(--border) bg-(--panel-muted) p-8 text-center">
                    <span className="mx-auto grid size-10 place-items-center rounded-full bg-(--panel) text-(--muted)">
                        <Eye className="size-5" aria-hidden="true" />
                    </span>
                    <p className="mt-3 text-sm font-semibold text-(--text)">
                        Select a target platform to preview
                    </p>
                    <p className="mt-1 text-xs text-(--muted)">
                        Check a connected account under "Publish to" to see how
                        your post will look.
                    </p>
                </div>
            ) : (
                <>
                    {definitions.length > 1 && (
                        <div
                            className="mt-4 flex flex-wrap gap-2"
                            role="tablist"
                            aria-label="Platform preview"
                        >
                            {definitions.map((definition) => {
                                const isActive =
                                    definition.id === activeDefinition.id;
                                const Icon = definition.icon;

                                return (
                                    <button
                                        key={definition.id}
                                        type="button"
                                        role="tab"
                                        aria-selected={isActive}
                                        onClick={() =>
                                            onActivePlatformChange(
                                                definition.id,
                                            )
                                        }
                                        className={`inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition focus:ring-4 focus:outline-none ${
                                            isActive
                                                ? 'border-(--border) bg-(--panel-muted) text-(--text)'
                                                : 'border-transparent text-(--muted) hover:bg-(--panel-muted) hover:text-(--text)'
                                        }`}
                                    >
                                        <Icon className="size-4" />
                                        {definition.label}
                                    </button>
                                );
                            })}
                        </div>
                    )}

                    <div className="mt-5">
                        {isEmpty ? (
                            <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-(--border) bg-(--panel-muted) p-8 text-center">
                                <span className="mx-auto grid size-10 place-items-center rounded-full bg-(--panel) text-(--muted)">
                                    <Eye
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <p className="mt-3 text-sm font-semibold text-(--text)">
                                    Your post will appear here
                                </p>
                                <p className="mt-1 text-xs text-(--muted)">
                                    Start typing or add an image to see a live
                                    preview.
                                </p>
                            </div>
                        ) : (
                            activeDefinition.render({
                                caption,
                                title,
                                imageUrl,
                                authorName,
                                platformLabel: activeDefinition.label,
                                requiresMedia: activeDefinition.requiresMedia,
                            })
                        )}
                    </div>
                </>
            )}
        </section>
    );
}
