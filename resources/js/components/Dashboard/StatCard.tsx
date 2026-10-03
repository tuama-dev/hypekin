import type { LucideIcon } from 'lucide-react';
import { MetricSparkline } from '@/components/Posts/MetricSparkline';
import { formatNumber } from '@/lib/formatNumber';
import { cn } from '@/lib/utils';

interface StatCardProps {
    label: string;
    value: number;
    icon: LucideIcon;
    hint?: string;
    sparklineValues?: (number | null)[];
    iconClassName?: string;
    sparklineClassName?: string;
}

export function StatCard({
    label,
    value,
    icon: Icon,
    hint,
    sparklineValues,
    iconClassName,
    sparklineClassName,
}: StatCardProps) {
    const hasSparkline =
        sparklineValues !== undefined &&
        sparklineValues.some((item) => item !== null && item > 0);

    return (
        <div className="rounded-2xl border border-(--border) bg-(--panel) p-4">
            <div className="flex items-start justify-between gap-3">
                <span
                    className={cn(
                        'grid size-10 shrink-0 place-items-center rounded-xl bg-(--panel-muted) text-(--color-accent-start)',
                        iconClassName,
                    )}
                >
                    <Icon className="size-5" />
                </span>
                {hasSparkline && sparklineValues && (
                    <MetricSparkline
                        values={sparklineValues}
                        colorClass={sparklineClassName}
                        className="hidden lg:inline-flex"
                    />
                )}
            </div>
            <p className="mt-4 text-2xl font-extrabold tracking-tight text-(--text) tabular-nums">
                {formatNumber(value)}
            </p>
            <p className="mt-0.5 text-xs font-semibold tracking-wider text-(--muted) uppercase">
                {label}
            </p>
            {hint && <p className="mt-1 text-xs text-(--muted)">{hint}</p>}
        </div>
    );
}
