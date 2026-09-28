import { cn } from '@/lib/utils';

interface MetricSparklineProps {
    values: (number | null)[];
    className?: string;
    colorClass?: string;
}

export function MetricSparkline({
    values,
    className,
    colorClass = 'stroke-(--color-accent-start)',
}: MetricSparklineProps) {
    const validNumbers = values.filter((v): v is number => v !== null && !Number.isNaN(v));

    if (validNumbers.length < 2) {
        return (
            <span className={cn('text-xs font-medium text-(--muted)', className)}>
                {validNumbers.length === 1 ? validNumbers[0] : 'No snapshots yet'}
            </span>
        );
    }

    const min = Math.min(...validNumbers);
    const max = Math.max(...validNumbers);
    const range = max - min || 1;
    const width = 120;
    const height = 36;
    const padding = 4;

    const points = validNumbers
        .map((val, index) => {
            const x = padding + (index / (validNumbers.length - 1)) * (width - padding * 2);
            const y = height - padding - ((val - min) / range) * (height - padding * 2);
            return `${x},${y}`;
        })
        .join(' ');

    return (
        <div className={cn('inline-flex items-center gap-2', className)}>
            <svg viewBox={`0 0 ${width} ${height}`} className="h-9 w-30 overflow-visible">
                <polyline
                    fill="none"
                    strokeWidth="2.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className={colorClass}
                    points={points}
                />
            </svg>
            <span className="text-xs font-bold text-(--text)">
                {validNumbers.at(-1)}
            </span>
        </div>
    );
}
