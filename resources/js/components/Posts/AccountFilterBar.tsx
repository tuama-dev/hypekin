import { Link } from '@inertiajs/react';
import {
    platformBrands,
    type PlatformValue,
} from '@/components/ui/platformBrands';
import { cn } from '@/lib/utils';

export interface AccountFilterItem {
    id: string;
    platform: { value: string; label: string };
    display_name: string;
    status: { value: string; label: string };
    avatar_url: string | null;
}

const FALLBACK_ACCOUNT_BORDER = 'border-(--border)';

function AccountFilterPill({
    label,
    href,
    isActive,
    avatarUrl = null,
    platform = null,
    title,
}: {
    label: string;
    href: string;
    isActive: boolean;
    avatarUrl?: string | null;
    platform?: string | null;
    title?: string;
}) {
    const brand =
        platform !== null
            ? (platformBrands[platform as PlatformValue] ?? null)
            : null;
    const initial = label.trim().charAt(0).toUpperCase();
    const borderClass = brand?.borderClass ?? FALLBACK_ACCOUNT_BORDER;

    return (
        <Link
            href={href}
            preserveScroll
            aria-current={isActive ? 'true' : undefined}
            className="group flex w-16 flex-col items-center gap-1.5"
        >
            <span
                className={cn(
                    'relative block aspect-square size-14 rounded-full border-2 transition',
                    borderClass,
                    isActive
                        ? cn(
                              'ring-2 ring-offset-2 ring-offset-(--dashboard-background)',
                              brand?.ringClass ??
                                  'ring-[var(--color-accent-start)]',
                          )
                        : 'opacity-75 group-hover:opacity-100',
                )}
            >
                <span className="block size-full overflow-hidden rounded-full">
                    {avatarUrl !== null ? (
                        <img
                            src={avatarUrl}
                            alt=""
                            loading="lazy"
                            className="size-full object-cover"
                        />
                    ) : (
                        <span
                            className={cn(
                                'grid size-full place-items-center text-base font-bold',
                                platform === null
                                    ? 'bg-(--panel-muted) text-(--muted)'
                                    : 'text-white uppercase ' +
                                          (brand?.buttonClass ??
                                              'bg-(--panel-muted) text-(--muted)'),
                            )}
                        >
                            {platform === null ? 'All' : initial}
                        </span>
                    )}
                </span>

                {brand !== null && (
                    <span
                        title={title}
                        className="absolute -bottom-0.5 -left-0.5 grid size-5 place-items-center rounded-full bg-(--dashboard-background) ring-1 ring-(--border)"
                    >
                        <brand.icon className="size-3" aria-hidden="true" />
                    </span>
                )}
            </span>

            <span className="w-full truncate text-center text-[11px] font-semibold text-(--muted) group-hover:text-(--text)">
                {label}
            </span>
        </Link>
    );
}

export function AccountFilterBar({
    accounts,
    activeAccountId,
    hrefFor,
    className,
}: {
    accounts: AccountFilterItem[];
    activeAccountId: string | null;
    hrefFor: (accountId: string | null) => string;
    className?: string;
}) {
    if (accounts.length === 0) {
        return null;
    }

    return (
        <nav
            aria-label="Filter posts by account"
            className={cn('mt-6', className)}
        >
            <ul className="flex items-end gap-3 overflow-x-auto pt-2 pb-1">
                <li className="shrink-0">
                    <AccountFilterPill
                        label="All accounts"
                        isActive={activeAccountId === null}
                        href={hrefFor(null)}
                    />
                </li>

                {accounts.map((account) => (
                    <li key={account.id} className="shrink-0">
                        <AccountFilterPill
                            label={account.display_name}
                            platform={account.platform.value}
                            avatarUrl={account.avatar_url}
                            title={`${account.display_name} · ${account.platform.label} · ${account.status.label}`}
                            isActive={activeAccountId === account.id}
                            href={hrefFor(account.id)}
                        />
                    </li>
                ))}
            </ul>
        </nav>
    );
}
