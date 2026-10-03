import { Link } from '@inertiajs/react';
import { Trophy, Users } from 'lucide-react';
import PostController from '@/actions/App/Http/Controllers/Application/PostController';
import {
    platformBrands,
    type PlatformValue,
} from '@/components/ui/platformBrands';
import { formatNumber } from '@/lib/formatNumber';
import { cn } from '@/lib/utils';
import type { BestPost } from './types';

interface BestPostCardProps {
    workspaceSlug: string;
    post: BestPost;
}

export function BestPostCard({ workspaceSlug, post }: BestPostCardProps) {
    const brand = platformBrands[post.platform as PlatformValue];

    return (
        <div className="relative overflow-hidden rounded-2xl border border-(--border) bg-(--panel) p-4">
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-x-0 top-0 h-24 bg-linear-to-b from-(--color-accent-start)/10 to-transparent"
            />

            <div className="relative flex items-center gap-2">
                <span className="grid size-8 place-items-center rounded-lg bg-linear-to-r from-(--color-accent-start) to-(--color-accent-end) text-(--color-accent-ink)">
                    <Trophy className="size-4" />
                </span>
                <p className="text-sm font-extrabold tracking-tight text-(--text)">
                    Top post this month
                </p>
            </div>

            <div className="relative mt-3 flex items-center gap-3">
                <span
                    className={cn(
                        'grid size-10 shrink-0 place-items-center rounded-xl text-white',
                        brand?.buttonClass ?? 'bg-(--muted)',
                    )}
                >
                    {brand ? (
                        <brand.icon className="size-5" />
                    ) : (
                        <Users className="size-5" />
                    )}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-bold text-(--text)">
                        {post.title ?? post.caption}
                    </p>
                    <p className="text-xs text-(--muted)">
                        {brand?.label ?? post.platform} ·{' '}
                        {formatNumber(post.reach)} reach
                    </p>
                </div>
                <Link
                    href={
                        PostController.show({
                            workspace: workspaceSlug,
                            post: post.post_id,
                        }).url
                    }
                    className="shrink-0 text-xs font-bold text-(--color-accent-start) hover:underline"
                >
                    View
                </Link>
            </div>
        </div>
    );
}
