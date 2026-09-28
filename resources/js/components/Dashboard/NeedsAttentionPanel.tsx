import { Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import PostController from '@/actions/App/Http/Controllers/Application/PostController';
import { platformBrands, type PlatformValue } from '@/components/ui/platformBrands';
import { cn } from '@/lib/utils';
import type { AttentionItem } from './types';

interface NeedsAttentionPanelProps {
    workspaceSlug: string;
    items: AttentionItem[];
}

export function NeedsAttentionPanel({ workspaceSlug, items }: NeedsAttentionPanelProps) {
    if (items.length === 0) {
        return (
            <div className="flex items-center gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600">
                    <CheckCircle2 className="size-5" />
                </span>
                <p className="text-sm text-(--muted)">Nothing needs attention right now.</p>
            </div>
        );
    }

    return (
        <ul className="divide-y divide-(--border) -my-1">
            {items.map((item) => {
                const brand = platformBrands[item.platform.value as PlatformValue];

                return (
                    <li key={item.post_id} className="flex items-start gap-3 py-2.5">
                        <span
                            className={cn(
                                'grid size-8 shrink-0 place-items-center rounded-lg text-white',
                                brand?.buttonClass ?? 'bg-rose-500',
                            )}
                        >
                            {brand ? (
                                <brand.icon className="size-4" />
                            ) : (
                                <AlertTriangle className="size-4" />
                            )}
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center justify-between gap-2">
                                <p className="truncate text-sm font-bold text-(--text)">
                                    {item.title ?? item.caption}
                                </p>
                                <Link
                                    href={PostController.show({
                                        workspace: workspaceSlug,
                                        post: item.post_id,
                                    }).url}
                                    className="shrink-0 text-xs font-bold text-(--color-accent-start) hover:underline"
                                >
                                    Review
                                </Link>
                            </div>
                            <p className="mt-0.5 truncate text-xs text-(--muted)">
                                {item.display_name}
                            </p>
                            {item.error_message && (
                                <p className="mt-0.5 truncate text-xs text-rose-600" title={item.error_message}>
                                    {item.error_message}
                                </p>
                            )}
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}