import { useEffect, useMemo, useState } from 'react';
import { Activity } from 'lucide-react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatNumber } from '@/lib/formatNumber';
import { cn } from '@/lib/utils';
import type { TrendMetric, TrendPoint } from './types';

const METRICS: { key: TrendMetric; label: string }[] = [
    { key: 'reach', label: 'Reach' },
    { key: 'impressions', label: 'Impressions' },
    { key: 'engagements', label: 'Engagements' },
];

const RANGES = [7, 14, 30] as const;

const METRIC_DOT_COLORS: Record<TrendMetric, string> = {
    reach: '#0ea5e9',
    impressions: '#8b5cf6',
    engagements: '#f43f5e',
};

interface DesignTokens {
    accentStart: string;
    muted: string;
    border: string;
    text: string;
}

const FALLBACK_TOKENS: DesignTokens = {
    accentStart: '#5f77df',
    muted: '#788195',
    border: '#e2e7f0',
    text: '#202535',
};

function readDesignTokens(): DesignTokens {
    const styles = getComputedStyle(document.documentElement);
    const read = (name: string): string =>
        styles.getPropertyValue(name).trim() ||
        FALLBACK_TOKENS[name as keyof DesignTokens];

    return {
        accentStart: read('--color-accent-start'),
        muted: read('--color-muted'),
        border: read('--color-line'),
        text: read('--color-ink'),
    };
}

function useDesignTokens(): DesignTokens {
    const [tokens, setTokens] = useState<DesignTokens>(FALLBACK_TOKENS);

    useEffect(() => {
        const refresh = () => setTokens(readDesignTokens());

        refresh();

        const observer = new MutationObserver(refresh);
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-theme', 'class'],
        });

        return () => observer.disconnect();
    }, []);

    return tokens;
}

function formatDate(date: string): string {
    return new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, {
        timeZone: 'UTC',
        month: 'short',
        day: 'numeric',
    });
}

const TICK_STYLE = { fontSize: 11, fontWeight: 500 } as const;

interface TrendTooltipProps {
    active?: boolean;
    payload?: Array<{ payload: TrendPoint }>;
    label?: string | number;
    metric: TrendMetric;
}

function TrendTooltip({ active, payload, label, metric }: TrendTooltipProps) {
    if (!active || payload === undefined || payload.length === 0) {
        return null;
    }

    const point = payload[0].payload;
    const selectedLabel =
        METRICS.find(({ key }) => key === metric)?.label ?? '';

    return (
        <div className="min-w-44 rounded-xl border border-(--border) bg-(--panel) px-3 py-2.5 shadow-xl">
            <p className="text-xs font-bold text-(--text)">
                {typeof label === 'string' ? formatDate(label) : label}
            </p>
            <div className="mt-1.5 space-y-1">
                {METRICS.map(({ key, label: metricName }) => {
                    const value = point[key];

                    return (
                        <div
                            key={key}
                            className="flex items-center justify-between gap-6 text-xs"
                        >
                            <span
                                className={cn(
                                    'flex items-center gap-1.5',
                                    key === metric
                                        ? 'font-semibold text-(--text)'
                                        : 'text-(--muted)',
                                )}
                            >
                                <span
                                    className="size-2 shrink-0 rounded-full"
                                    style={{
                                        backgroundColor: METRIC_DOT_COLORS[key],
                                    }}
                                />
                                {metricName}
                            </span>
                            <span
                                className={cn(
                                    'tabular-nums',
                                    key === metric
                                        ? 'font-extrabold text-(--color-accent-start)'
                                        : 'font-semibold text-(--text)',
                                )}
                            >
                                {value > 0 || key === metric
                                    ? formatNumber(value)
                                    : '—'}
                            </span>
                        </div>
                    );
                })}
            </div>
            <p className="mt-1.5 text-[10px] font-semibold tracking-wider text-(--muted) uppercase">
                {selectedLabel} · daily snapshot
            </p>
        </div>
    );
}

interface TrendChartProps {
    data: TrendPoint[];
    className?: string;
}

export function TrendChart({ data, className }: TrendChartProps) {
    const [mounted, setMounted] = useState(false);
    const [metric, setMetric] = useState<TrendMetric>('reach');
    const [range, setRange] = useState<(typeof RANGES)[number]>(30);
    const tokens = useDesignTokens();

    useEffect(() => {
        setMounted(true);
    }, []);

    const windowed = useMemo(() => data.slice(-range), [data, range]);
    const series = useMemo(
        () => windowed.map((point) => point[metric]),
        [windowed, metric],
    );
    const grouped = useMemo(
        () =>
            METRICS.map(({ key, label }) => ({
                key,
                label,
                total: windowed.reduce(
                    (sum, point) => sum + (point[key] ?? 0),
                    0,
                ),
            })),
        [windowed],
    );
    const total = grouped.find(({ key }) => key === metric)?.total ?? 0;
    const hasData = series.some((value) => value > 0);
    const showChart = mounted && hasData;

    const toggleClasses = (active: boolean) =>
        cn(
            'rounded-full px-2.5 py-1 text-[11px] font-bold transition',
            active
                ? 'bg-linear-to-r from-(--color-accent-start) to-(--color-accent-end) text-(--color-accent-ink)'
                : 'text-(--muted) hover:bg-(--panel-muted) hover:text-(--text)',
        );

    const chartLabel = `${METRICS.find(({ key }) => key === metric)?.label} over the last ${range} days`;

    return (
        <div
            className={cn(
                'flex flex-col rounded-2xl border border-(--border) bg-(--panel) p-4',
                className,
            )}
        >
            <header className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 className="text-base font-extrabold tracking-tight text-(--text)">
                        Performance trend
                    </h3>
                    <p className="mt-0.5 text-xs text-(--muted)">
                        Last {range} days · daily snapshots
                    </p>
                </div>
                <div className="flex items-center gap-1.5">
                    <div className="flex items-center gap-0.5 rounded-lg bg-(--panel-muted) p-1">
                        {METRICS.map(({ key, label }) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setMetric(key)}
                                className={toggleClasses(metric === key)}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <div className="flex items-center gap-0.5 rounded-lg bg-(--panel-muted) p-1">
                        {RANGES.map((days) => (
                            <button
                                key={days}
                                type="button"
                                onClick={() => setRange(days)}
                                className={toggleClasses(range === days)}
                            >
                                {days}D
                            </button>
                        ))}
                    </div>
                </div>
            </header>

            <div className="mt-1 flex items-baseline gap-2">
                <span className="text-2xl font-extrabold tracking-tight text-(--text) tabular-nums">
                    {formatNumber(total)}
                </span>
                <span className="text-xs font-semibold tracking-wider text-(--muted) uppercase">
                    {METRICS.find(({ key }) => key === metric)?.label}
                </span>
            </div>

            <div className="mt-3 h-72" role="img" aria-label={chartLabel}>
                {showChart ? (
                    <ResponsiveContainer
                        width="100%"
                        height="100%"
                        minWidth={0}
                    >
                        <AreaChart
                            data={windowed}
                            margin={{ top: 8, right: 4, bottom: 0, left: 0 }}
                        >
                            <defs>
                                <linearGradient
                                    id="trend-area-fill"
                                    x1="0"
                                    y1="0"
                                    x2="0"
                                    y2="1"
                                >
                                    <stop
                                        offset="0%"
                                        stopColor={tokens.accentStart}
                                        stopOpacity={0.25}
                                    />
                                    <stop
                                        offset="100%"
                                        stopColor={tokens.accentStart}
                                        stopOpacity={0}
                                    />
                                </linearGradient>
                            </defs>

                            <CartesianGrid
                                stroke={tokens.border}
                                strokeDasharray="3 3"
                                vertical={false}
                            />

                            <XAxis
                                dataKey="date"
                                tickFormatter={formatDate}
                                tick={{ ...TICK_STYLE, fill: tokens.muted }}
                                tickMargin={8}
                                tickLine={false}
                                axisLine={false}
                                minTickGap={32}
                                interval="preserveStartEnd"
                                stroke={tokens.border}
                            />

                            <YAxis
                                tickFormatter={(value: number) =>
                                    formatNumber(value)
                                }
                                tick={{ ...TICK_STYLE, fill: tokens.muted }}
                                tickMargin={6}
                                tickLine={false}
                                axisLine={false}
                                width={44}
                                allowDecimals={false}
                                stroke={tokens.border}
                            />

                            <Tooltip
                                cursor={{
                                    stroke: tokens.border,
                                    strokeDasharray: '4 4',
                                }}
                                content={<TrendTooltip metric={metric} />}
                            />

                            <Area
                                type="monotone"
                                dataKey={metric}
                                stroke={tokens.accentStart}
                                strokeWidth={2.5}
                                strokeLinecap="round"
                                fill="url(#trend-area-fill)"
                                activeDot={{
                                    r: 4,
                                    strokeWidth: 0,
                                    fill: tokens.accentStart,
                                }}
                                isAnimationActive
                                animationDuration={400}
                            />
                        </AreaChart>
                    </ResponsiveContainer>
                ) : (
                    <div className="flex h-full flex-col items-center justify-center gap-2 rounded-xl bg-(--panel-muted)/40 text-center">
                        <span className="grid size-10 place-items-center rounded-xl bg-(--panel) text-(--muted)">
                            <Activity className="size-5" />
                        </span>
                        {mounted && (
                            <p className="max-w-60 text-sm text-(--muted)">
                                No metrics in this period yet — publish a post
                                to start tracking.
                            </p>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
