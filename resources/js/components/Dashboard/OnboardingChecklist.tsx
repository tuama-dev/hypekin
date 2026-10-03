import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { FolderOpen, Check, Link2, Send } from 'lucide-react';
import MediaController from '@/actions/App/Http/Controllers/Application/MediaController';
import PostController from '@/actions/App/Http/Controllers/Application/PostController';
import SocialAccountController from '@/actions/App/Http/Controllers/Application/SocialAccountController';

interface OnboardingChecklistProps {
    workspaceSlug: string;
    hasAccounts: boolean;
    hasMedia: boolean;
    hasPosts: boolean;
}

interface ChecklistStep {
    icon: LucideIcon;
    title: string;
    description: string;
    href: string;
    done: boolean;
}

export function OnboardingChecklist({
    workspaceSlug,
    hasAccounts,
    hasMedia,
    hasPosts,
}: OnboardingChecklistProps) {
    const steps: ChecklistStep[] = [
        {
            icon: Link2,
            title: 'Connect a social account',
            description: 'Link LinkedIn, Facebook, Instagram, or TikTok.',
            href: SocialAccountController.index({ workspace: workspaceSlug })
                .url,
            done: hasAccounts,
        },
        {
            icon: FolderOpen,
            title: 'Upload media',
            description: 'Add images and videos to reuse across posts.',
            href: MediaController.index({ workspace: workspaceSlug }).url,
            done: hasMedia,
        },
        {
            icon: Send,
            title: 'Create your first post',
            description: 'Pick your channels, set a schedule, and publish.',
            href: PostController.create({ workspace: workspaceSlug }).url,
            done: hasPosts,
        },
    ];

    return (
        <ol className="grid gap-3 sm:grid-cols-3">
            {steps.map((step, index) =>
                step.done ? (
                    <li
                        key={step.title}
                        className="flex items-center gap-3 rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4"
                    >
                        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600">
                            <Check className="size-5" />
                        </span>
                        <div className="min-w-0">
                            <p className="text-sm font-extrabold text-(--text)">
                                {step.title}
                            </p>
                            <p className="mt-0.5 text-xs text-(--muted)">
                                Complete
                            </p>
                        </div>
                    </li>
                ) : (
                    <li key={step.title}>
                        <Link
                            href={step.href}
                            className="group relative flex h-full items-center gap-3 rounded-2xl border border-(--border) bg-(--panel) p-4 transition hover:border-(--color-accent-start) hover:shadow-md"
                        >
                            <span className="absolute top-4 left-4 text-[11px] font-extrabold text-(--muted)">
                                {index + 1}
                            </span>
                            <span className="mt-4 grid size-10 shrink-0 place-items-center rounded-xl bg-(--panel-muted) text-(--muted) transition group-hover:bg-linear-to-r group-hover:from-(--color-accent-start) group-hover:to-(--color-accent-end) group-hover:text-(--color-accent-ink)">
                                <step.icon className="size-5" />
                            </span>
                            <div className="mt-4 min-w-0">
                                <p className="text-sm font-extrabold text-(--text)">
                                    {step.title}
                                </p>
                                <p className="mt-0.5 text-xs text-(--muted)">
                                    {step.description}
                                </p>
                            </div>
                        </Link>
                    </li>
                ),
            )}
        </ol>
    );
}
