import { CheckCircle2, Plus } from 'lucide-react';
import { platformBrands } from '@/components/ui/platformBrands';
import type { PlatformValue } from '@/components/ui/platformBrands';

interface PlatformView {
    value: string;
    label: string;
    configured: boolean;
    connect_url: string | null;
}

interface AccountView {
    id: string;
    platform: { value: string; label: string };
    display_name: string;
    status: { value: string; label: string };
    token_expires_at: string | null;
    connected_at: string | null;
}

interface PlatformConnectSectionProps {
    platforms: PlatformView[];
    accounts: AccountView[];
}

export function PlatformConnectSection({
    platforms,
    accounts,
}: PlatformConnectSectionProps) {
    const connectedCounts = accounts.reduce<Record<string, number>>(
        (counts, account) => {
            counts[account.platform.value] =
                (counts[account.platform.value] ?? 0) + 1;
            return counts;
        },
        {},
    );

    const connectable = platforms.filter((platform) => platform.configured);

    if (connectable.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="connect-platforms-heading" className="mt-6">
            <h2
                id="connect-platforms-heading"
                className="text-xs font-bold uppercase tracking-widest text-(--muted)"
            >
                Connect a platform
            </h2>

            <ul className="mt-3 grid gap-4 sm:grid-cols-2">
                {connectable.map((platform) => {
                    const brand =
                        platformBrands[platform.value as PlatformValue];

                    if (brand === undefined) {
                        return null;
                    }

                    const Icon = brand.icon;
                    const count = connectedCounts[platform.value] ?? 0;

                    return (
                        <li
                            key={platform.value}
                            className="flex flex-wrap items-center gap-4 rounded-2xl border border-(--border) bg-(--panel) p-4"
                        >
                            <span
                                className={`grid size-10 shrink-0 place-items-center rounded-xl ${brand.chipClass}`}
                            >
                                <Icon className="size-5" />
                            </span>

                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-bold text-(--text)">
                                    {brand.label}
                                </p>
                                <p className="mt-0.5 text-xs text-(--muted)">
                                    {brand.subtitle}
                                </p>
                            </div>

                            <div className="flex shrink-0 items-center gap-2">
                                {count > 0 && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-600">
                                        <CheckCircle2
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                        {count > 1
                                            ? `Connected (${count})`
                                            : 'Connected'}
                                    </span>
                                )}

                                <a
                                    href={platform.connect_url ?? '#'}
                                    className={`inline-flex h-9 items-center gap-1.5 rounded-lg px-4 text-sm font-bold text-white shadow-sm transition hover:-translate-y-0.5 hover:brightness-110 focus:ring-4 focus:ring-white/40 focus:outline-none ${brand.buttonClass}`}
                                >
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Connect
                                </a>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}