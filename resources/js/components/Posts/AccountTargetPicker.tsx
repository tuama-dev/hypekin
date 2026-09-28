import { useState } from 'react';
import { CheckCheck, ChevronDown, Unlink } from 'lucide-react';
import {
    platformBrands,
    type PlatformValue,
} from '@/components/ui/platformBrands';
import { cn } from '@/lib/utils';

export interface PublishableAccount {
    id: string;
    platform: { value: string; label: string };
    display_name: string;
    avatar_url: string | null;
}

const PLATFORM_ORDER: PlatformValue[] = [
    'facebook',
    'instagram',
    'linkedin',
    'tiktok',
];

function listWithConjunction(
    items: string[],
    conjunction: 'and' | 'or',
): string {
    if (items.length <= 1) {
        return items.join('');
    }

    return `${items.slice(0, -1).join(', ')} ${conjunction} ${items.at(-1)}`;
}

interface AccountTargetPickerProps {
    accounts: PublishableAccount[];
    platforms: string[];
    targets: string[];
    onChangeTargets: (targets: string[]) => void;
    connectHref: string;
    errors?: string;
    requiredMediaPlatforms: Set<string>;
    requiredTitlePlatforms: Set<string>;
    mediaAttached: boolean;
    titleFilled: boolean;
}

export function AccountTargetPicker({
    accounts,
    platforms,
    targets,
    onChangeTargets,
    connectHref,
    errors,
    requiredMediaPlatforms,
    requiredTitlePlatforms,
    mediaAttached,
    titleFilled,
}: AccountTargetPickerProps) {
    const toggle = (current: string[], accountId: string): string[] =>
        current.includes(accountId)
            ? current.filter((id) => id !== accountId)
            : [...current, accountId];

    const selectAll = (current: string[], groupIds: string[]): string[] => [
        ...new Set([...current, ...groupIds]),
    ];

    const deselectAll = (current: string[], groupIds: string[]) =>
        current.filter((id) => !groupIds.includes(id));

    const groups = PLATFORM_ORDER.map((platform) => {
        const platformAccounts = accounts.filter(
            (account) => account.platform.value === platform,
        );

        return {
            platform,
            brand: platformBrands[platform],
            accounts: platformAccounts,
        };
    }).filter((group) => group.accounts.length > 0);

    const [expandedGroups, setExpandedGroups] = useState<Set<string>>(() => {
        const initial = new Set<string>();

        for (const group of groups) {
            if (
                group.accounts.some((account) => targets.includes(account.id))
            ) {
                initial.add(group.platform);
            }
        }

        return initial;
    });

    function toggleGroup(platform: string) {
        setExpandedGroups((current) => {
            const next = new Set(current);

            if (next.has(platform)) {
                next.delete(platform);
            } else {
                next.add(platform);
            }

            return next;
        });
    }

    function expandGroup(platform: string) {
        setExpandedGroups((current) => new Set(current).add(platform));
    }

    const selectedCount = targets.length;

    if (accounts.length === 0) {
        return (
            <div className="mt-4 rounded-xl border border-dashed border-(--border) bg-(--panel-muted) p-6 text-center text-sm text-(--muted)">
                No publishable accounts yet. Connect{' '}
                {listWithConjunction(platforms, 'or')} from the{' '}
                <a
                    href={connectHref}
                    className="font-semibold text-(--text) underline underline-offset-2"
                >
                    Accounts page
                </a>
                .
            </div>
        );
    }

    return (
        <>
            <div className="mt-3 flex items-center justify-between gap-4">
                <p className="text-sm font-semibold text-(--text)">
                    {selectedCount} of {accounts.length} accounts selected
                </p>
                {selectedCount > 0 && (
                    <button
                        type="button"
                        onClick={() => {
                            onChangeTargets([]);
                            setExpandedGroups(new Set());
                        }}
                        className="flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text)"
                    >
                        <Unlink className="size-3.5" />
                        Clear all
                    </button>
                )}
            </div>

            {errors && <p className="mt-3 text-sm text-rose-400">{errors}</p>}

            <div className="mt-4 space-y-5">
                {groups.map(({ platform, brand, accounts: groupAccounts }) => {
                    const selectedInGroup = groupAccounts.filter((account) =>
                        targets.includes(account.id),
                    ).length;
                    const groupAllSelected =
                        selectedInGroup === groupAccounts.length;

                    return (
                        <section key={platform}>
                            <div className="flex items-center justify-between gap-3">
                                <button
                                    type="button"
                                    onClick={() => toggleGroup(platform)}
                                    aria-expanded={expandedGroups.has(platform)}
                                    aria-controls={`${platform}-accounts`}
                                    aria-label={`${
                                        expandedGroups.has(platform)
                                            ? 'Collapse'
                                            : 'Expand'
                                    } ${brand.label} accounts`}
                                    className="-mx-1 flex min-w-0 items-center gap-2 rounded-md px-1 py-1 transition hover:bg-(--panel-muted)"
                                >
                                    <brand.icon
                                        className={cn(
                                            'size-4 shrink-0',
                                            brand.textClass,
                                        )}
                                    />
                                    <span className="truncate text-sm font-bold text-(--text)">
                                        {brand.label}
                                    </span>
                                    <span className="shrink-0 text-xs font-semibold text-(--muted)">
                                        {selectedInGroup}/{groupAccounts.length}
                                    </span>
                                    <ChevronDown
                                        className={cn(
                                            'size-4 shrink-0 text-(--muted) transition-transform duration-200',
                                            expandedGroups.has(platform) &&
                                                'rotate-180',
                                        )}
                                        aria-hidden="true"
                                    />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        const groupIds = groupAccounts.map(
                                            (account) => account.id,
                                        );

                                        if (groupAllSelected) {
                                            onChangeTargets(
                                                deselectAll(targets, groupIds),
                                            );
                                        } else {
                                            onChangeTargets(
                                                selectAll(targets, groupIds),
                                            );
                                            expandGroup(platform);
                                        }
                                    }}
                                    className={cn(
                                        'flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold transition',
                                        groupAllSelected
                                            ? 'text-(--muted) hover:bg-(--panel-muted) hover:text-(--text)'
                                            : 'text-(--color-accent-start) hover:bg-(--color-accent-start)/10',
                                    )}
                                >
                                    <CheckCheck className="size-3.5" />
                                    {groupAllSelected
                                        ? 'Deselect all'
                                        : 'Select all'}
                                </button>
                            </div>

                            {expandedGroups.has(platform) && (
                                <ul
                                    id={`${platform}-accounts`}
                                    className="mt-2.5 grid gap-2"
                                >
                                    {groupAccounts.map((account) => {
                                        const isSelected = targets.includes(
                                            account.id,
                                        );
                                        const initial = account.display_name
                                            .trim()
                                            .charAt(0)
                                            .toUpperCase();

                                        return (
                                            <li key={account.id}>
                                                <label
                                                    title={`${account.display_name} · ${account.platform.label}`}
                                                    className={cn(
                                                        'flex cursor-pointer items-center gap-3 rounded-xl border px-3.5 py-2.5 transition',
                                                        isSelected
                                                            ? 'border-[var(--color-accent-start)]/50 bg-(--panel-muted)'
                                                            : 'border-(--border) hover:bg-(--panel-muted)/60',
                                                    )}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={isSelected}
                                                        onChange={() =>
                                                            onChangeTargets(
                                                                toggle(
                                                                    targets,
                                                                    account.id,
                                                                ),
                                                            )
                                                        }
                                                        className="size-4 shrink-0 accent-[var(--color-accent-start)]"
                                                    />
                                                    <span
                                                        className={cn(
                                                            'grid size-8 shrink-0 place-items-center overflow-hidden rounded-full',
                                                            brand.chipClass,
                                                        )}
                                                    >
                                                        {account.avatar_url !==
                                                        null ? (
                                                            <img
                                                                src={
                                                                    account.avatar_url
                                                                }
                                                                alt=""
                                                                loading="lazy"
                                                                className="size-full object-cover"
                                                            />
                                                        ) : (
                                                            <span className="text-xs font-bold uppercase">
                                                                {initial}
                                                            </span>
                                                        )}
                                                    </span>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-sm font-bold text-(--text)">
                                                            {
                                                                account.display_name
                                                            }
                                                        </span>
                                                        <span className="mt-0.5 block text-xs text-(--muted)">
                                                            {
                                                                account.platform
                                                                    .label
                                                            }
                                                        </span>
                                                    </span>
                                                    {isSelected &&
                                                        requiredMediaPlatforms.has(
                                                            account.platform
                                                                .value,
                                                        ) &&
                                                        !mediaAttached && (
                                                            <span className="shrink-0 rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-semibold text-amber-600">
                                                                Image required
                                                            </span>
                                                        )}
                                                    {isSelected &&
                                                        requiredTitlePlatforms.has(
                                                            account.platform
                                                                .value,
                                                        ) &&
                                                        !titleFilled && (
                                                            <span className="shrink-0 rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-semibold text-amber-600">
                                                                Title required
                                                            </span>
                                                        )}
                                                </label>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </section>
                    );
                })}
            </div>
        </>
    );
}
