import { LayoutPanelTop } from 'lucide-react';
import {
    platformBrands,
    type PlatformValue,
} from '@/components/ui/platformBrands';
import { formatNumber } from '@/lib/formatNumber';
import { cn } from '@/lib/utils';
import type { PlatformShare } from './types';

interface PlatformMixProps {
    items: PlatformShare[];
}

export function PlatformMix({ items }: PlatformMixProps) {
    const max = Math.max(...items.map((item) => item.reach), 0);

    if (items.length === 0) {
        return (
            <div className="flex flex-col items-center gap-2 py-6 text-center">
                <span className="grid size-10 place-items-center rounded-xl bg-(--panel-muted) text-(--muted)">
                    <LayoutPanelTop className="size-5" />
                </span>
                <p className="text-sm text-(--muted)">
                    No platform metrics yet.
                </p>
            </div>
        );
    }

    return (
        <ul className="space-y-4">
            {items.map((item) => {
                const brand = platformBrands[item.platform as PlatformValue];

                return (
                    <li key={item.platform}>
                        <div className="flex items-center justify-between gap-2 text-sm">
                            <span className="flex min-w-0 items-center gap-2 font-bold text-(--text)">
                                {brand ? (
                                    <brand.icon
                                        className={cn(
                                            'size-4 shrink-0',
                                            brand.textClass,
                                        )}
                                    />
                                ) : (
                                    <span className="size-4 shrink-0 rounded bg-(--panel-muted)" />
                                )}
                                <span className="truncate">{item.label}</span>
                            </span>
                            <span className="shrink-0 text-xs font-bold text-(--muted) tabular-nums">
                                {formatNumber(item.reach)}
                            </span>
                        </div>
                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-(--panel-muted)">
                            <div
                                className={cn(
                                    'h-full rounded-full',
                                    brand?.solidClass ??
                                        'bg-(--color-accent-start)',
                                )}
                                style={{
                                    width: `${max === 0 ? 0 : (item.reach / max) * 100}%`,
                                }}
                            />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
