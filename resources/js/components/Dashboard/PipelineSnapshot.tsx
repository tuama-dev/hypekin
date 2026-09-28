import { Layers } from 'lucide-react';
import {
    postStatusSwatchClasses,
    type PostStatusValue,
} from '@/components/ui/postStatus';
import { cn } from '@/lib/utils';
import type { PipelineStatus } from './types';

interface PipelineSnapshotProps {
    items: PipelineStatus[];
}

export function PipelineSnapshot({ items }: PipelineSnapshotProps) {
    const total = items.reduce((sum, item) => sum + item.count, 0);

    if (total === 0) {
        return (
            <div className="flex flex-col items-center gap-2 py-6 text-center">
                <span className="grid size-10 place-items-center rounded-xl bg-(--panel-muted) text-(--muted)">
                    <Layers className="size-5" />
                </span>
                <p className="text-sm text-(--muted)">
                    No posts in the pipeline yet.
                </p>
            </div>
        );
    }

    return (
        <ul className="space-y-3">
            {items.map((item) => (
                <li key={item.status}>
                    <div className="flex items-center justify-between gap-2 text-sm">
                        <span className="flex min-w-0 items-center gap-2 font-bold text-(--text)">
                            <span
                                className={cn(
                                    'size-2.5 shrink-0 rounded-full',
                                    postStatusSwatchClasses[
                                        item.status as PostStatusValue
                                    ] ?? 'bg-(--muted)',
                                )}
                                aria-hidden="true"
                            />
                            <span className="truncate">{item.label}</span>
                        </span>
                        <span className="shrink-0 text-xs font-bold text-(--muted) tabular-nums">
                            {item.count}
                        </span>
                    </div>
                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-(--panel-muted)">
                        <div
                            className={cn(
                                'h-full rounded-full',
                                postStatusSwatchClasses[
                                    item.status as PostStatusValue
                                ] ?? 'bg-(--muted)',
                            )}
                            style={{ width: `${(item.count / total) * 100}%` }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}
