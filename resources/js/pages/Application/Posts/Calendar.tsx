import { Head, Link, router, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import {
    CalendarPlus,
    ChevronLeft,
    ChevronRight,
    Clock,
    FileText,
    Plus,
} from "lucide-react";
import { toast } from "sonner";
import AuthenticatedLayout from "@/components/Layout/AuthenticatedLayout";
import {
    AccountFilterBar,
    type AccountFilterItem,
} from "@/components/Posts/AccountFilterBar";
import { RescheduleModal } from "@/components/Posts/RescheduleModal";
import { Select } from "@/components/ui/Select";
import PostController from "@/actions/App/Http/Controllers/Application/PostController";
import CalendarController from "@/actions/App/Http/Controllers/Application/CalendarController";
import {
    platformBrands,
    type PlatformValue,
} from "@/components/ui/platformBrands";
import {
    FALLBACK_STATUS_CHIP_CLASS,
    FALLBACK_STATUS_SWATCH_CLASS,
    postStatusChipClasses,
    postStatusLegend,
    postStatusSwatchClasses,
    type PostStatusValue,
} from "@/components/ui/postStatus";
import { cn } from "@/lib/utils";

interface CalendarTarget {
    id: string;
    platform: { value: string; label: string };
    display_name: string;
    status: { value: string; label: string };
}

interface CalendarMedia {
    id: string;
    url: string;
}

interface CalendarPost {
    id: string;
    status: { value: string; label: string };
    scheduled_at: string | null;
    created_at: string | null;
    caption: string;
    targets: CalendarTarget[];
    media: CalendarMedia[];
}

interface CalendarPageProps {
    month: string;
    posts: CalendarPost[];
    accounts: AccountFilterItem[];
    filters: {
        account: string | null;
    };
}

const WEEKDAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

function pad(value: number): string {
    return String(value).padStart(2, "0");
}

function monthKey(date: Date): string {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
}

function parseMonthKey(key: string): Date {
    const [year, month] = key.split("-").map(Number);

    return new Date(year, month - 1, 1);
}

function addMonths(date: Date, amount: number): Date {
    return new Date(date.getFullYear(), date.getMonth() + amount, 1);
}

function dayKey(date: Date): string {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function postDate(post: CalendarPost): Date {
    return post.scheduled_at !== null
        ? new Date(post.scheduled_at)
        : new Date(post.created_at ?? "");
}

function formatPostTime(post: CalendarPost): string {
    return postDate(post).toLocaleString(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
    });
}

export default function Calendar({
    month,
    posts,
    accounts,
    filters,
}: CalendarPageProps) {
    const { auth, flash } = usePage().props;
    const workspace = auth.workspace;

    const [viewMonth, setViewMonth] = useState<Date>(() =>
        parseMonthKey(month),
    );
    const [selectedDay, setSelectedDay] = useState<Date | null>(() => {
        const today = new Date();
        return monthKey(today) === month
            ? new Date(today.getFullYear(), today.getMonth(), today.getDate())
            : null;
    });
    const [rescheduling, setRescheduling] = useState<CalendarPost | null>(null);

    useEffect(() => {
        setViewMonth(parseMonthKey(month));
    }, [month]);

    useEffect(() => {
        if (flash.success) {
            toast.success(flash.success);
        } else if (flash.error) {
            toast.error(flash.error);
        }
    }, [flash]);

    if (workspace === null) {
        return null;
    }

    const currentWorkspace = workspace;

    const cells = useMemo(() => {
        const year = viewMonth.getFullYear();
        const monthIndex = viewMonth.getMonth();
        const firstDay = new Date(year, monthIndex, 1);
        const startOffset = (firstDay.getDay() + 6) % 7;
        const gridStart = new Date(year, monthIndex, 1 - startOffset);

        return Array.from(
            { length: 42 },
            (_, index) =>
                new Date(
                    gridStart.getFullYear(),
                    gridStart.getMonth(),
                    gridStart.getDate() + index,
                ),
        );
    }, [viewMonth]);

    const postsByDay = useMemo(() => {
        const grouped = new Map<string, CalendarPost[]>();

        for (const post of posts) {
            const key = dayKey(postDate(post));
            const bucket = grouped.get(key) ?? [];
            bucket.push(post);
            grouped.set(key, bucket);
        }

        return grouped;
    }, [posts]);

    const selectedPosts =
        selectedDay === null ? [] : (postsByDay.get(dayKey(selectedDay)) ?? []);

    const selectedIsInGrid =
        selectedDay === null ||
        cells.some((cell) => dayKey(cell) === dayKey(selectedDay));

    const effectiveSelectedDay = selectedIsInGrid ? selectedDay : null;

    const windowMin = addMonths(parseMonthKey(month), -6);
    const windowMax = addMonths(parseMonthKey(month), 6);

    function goToMonth(target: Date) {
        if (
            target.getTime() < windowMin.getTime() ||
            target.getTime() > windowMax.getTime()
        ) {
            router.get(
                CalendarController.index({
                    workspace: currentWorkspace.slug,
                }).url,
                {
                    month: monthKey(target),
                    ...(filters.account ? { account: filters.account } : {}),
                },
                { preserveScroll: true },
            );

            return;
        }

        setViewMonth(target);
    }

    return (
        <AuthenticatedLayout>
            <Head title="Calendar" />

            <div className="flex w-full flex-col px-4 py-8 sm:px-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-extrabold tracking-tight text-(--text)">
                            Calendar
                        </h1>
                        <p className="mt-1 text-sm text-(--muted)">
                            Everything {currentWorkspace.name} has scheduled or
                            published, by the day.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Select
                            value={String(viewMonth.getFullYear())}
                            onValueChange={(year) =>
                                goToMonth(
                                    new Date(
                                        Number(year),
                                        viewMonth.getMonth(),
                                        1,
                                    ),
                                )
                            }
                            aria-label="Select year"
                            options={Array.from(
                                {
                                    length:
                                        windowMax.getFullYear() -
                                        windowMin.getFullYear() +
                                        1,
                                },
                                (_, index) => {
                                    const year =
                                        windowMin.getFullYear() + index;

                                    return {
                                        value: String(year),
                                        label: String(year),
                                    };
                                },
                            )}
                        />
                        <Select
                            value={String(viewMonth.getMonth())}
                            onValueChange={(month) =>
                                goToMonth(
                                    new Date(
                                        viewMonth.getFullYear(),
                                        Number(month),
                                        1,
                                    ),
                                )
                            }
                            aria-label="Select month"
                            options={Array.from({ length: 12 }, (_, index) => ({
                                value: String(index),
                                label: new Date(
                                    2000,
                                    index,
                                    1,
                                ).toLocaleDateString(undefined, {
                                    month: "long",
                                }),
                            }))}
                        />
                        <button
                            type="button"
                            onClick={() => goToMonth(addMonths(viewMonth, -1))}
                            className="ml-7 grid size-9 place-items-center rounded-lg border border-(--border) text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text) focus:ring-4 focus:ring-[color:var(--color-line)]/40 focus:outline-none"
                            aria-label="Previous month"
                        >
                            <ChevronLeft className="size-4" />
                        </button>
                        <button
                            type="button"
                            onClick={() => goToMonth(new Date(Date.now()))}
                            className="rounded-lg border border-(--border) px-4 py-2 text-sm font-semibold text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text) focus:ring-4 focus:ring-[color:var(--color-line)]/40 focus:outline-none"
                        >
                            Today
                        </button>
                        <button
                            type="button"
                            onClick={() => goToMonth(addMonths(viewMonth, 1))}
                            className="grid size-9 place-items-center rounded-lg border border-(--border) text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text) focus:ring-4 focus:ring-[color:var(--color-line)]/40 focus:outline-none"
                            aria-label="Next month"
                        >
                            <ChevronRight className="size-4" />
                        </button>
                    </div>
                </header>

                <AccountFilterBar
                    accounts={accounts}
                    activeAccountId={filters.account}
                    hrefFor={(accountId) =>
                        CalendarController.index(
                            { workspace: currentWorkspace.slug },
                            {
                                query: {
                                    month,
                                    ...(accountId
                                        ? { account: accountId }
                                        : {}),
                                },
                            },
                        ).url
                    }
                />

                <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_24rem] lg:items-start">
                    <section className="rounded-2xl border border-(--border) bg-(--panel) p-4">
                        <div className="mb-3">
                            <h2 className="text-base font-bold text-(--text)">
                                {viewMonth.toLocaleDateString(undefined, {
                                    month: "long",
                                    year: "numeric",
                                })}
                            </h2>
                        </div>

                        <ul className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-(--border) pb-3">
                            {postStatusLegend.map((status) => (
                                <li
                                    key={status.value}
                                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-(--muted)"
                                >
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            "size-2 rounded-full",
                                            postStatusSwatchClasses[
                                                status.value
                                            ] ?? FALLBACK_STATUS_SWATCH_CLASS,
                                        )}
                                    />
                                    {status.label}
                                </li>
                            ))}
                        </ul>

                        <div className="grid grid-cols-7 gap-px">
                            {WEEKDAYS.map((weekday) => (
                                <div
                                    key={weekday}
                                    className="px-2 pb-2 text-center text-xs font-bold tracking-wide text-(--muted) uppercase"
                                >
                                    {weekday}
                                </div>
                            ))}
                        </div>

                        <div className="grid grid-cols-7 gap-px">
                            {cells.map((cell) => {
                                const key = dayKey(cell);
                                const dayPosts = postsByDay.get(key) ?? [];
                                const isToday = key === dayKey(new Date());
                                const isSelected =
                                    effectiveSelectedDay !== null &&
                                    key === dayKey(effectiveSelectedDay);
                                const inMonth =
                                    cell.getMonth() === viewMonth.getMonth();

                                return (
                                    <button
                                        key={key}
                                        type="button"
                                        onClick={() => setSelectedDay(cell)}
                                        className={cn(
                                            "flex min-h-28 flex-col items-stretch gap-1.5 rounded-xl border p-1.5 text-left transition focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none sm:p-2",
                                            isSelected
                                                ? "border-[var(--color-accent-start)] bg-[var(--color-panel-muted)]"
                                                : inMonth
                                                  ? "border-(--border) hover:bg-(--panel-muted)"
                                                  : "border-(--border) opacity-40 hover:bg-(--panel-muted) hover:opacity-70",
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                "grid size-6 place-items-center rounded-full text-xs font-semibold",
                                                isToday
                                                    ? "bg-[var(--color-accent-start)] text-[var(--color-accent-ink)]"
                                                    : inMonth
                                                      ? "text-(--text)"
                                                      : "text-(--muted)",
                                            )}
                                        >
                                            {cell.getDate()}
                                        </span>

                                        <div className="flex min-w-0 flex-col gap-1">
                                            {dayPosts
                                                .slice(0, 2)
                                                .map((post) => (
                                                    <span
                                                        key={post.id}
                                                        title={`${post.status.label} · ${formatPostTime(post)} · ${post.caption || "Untitled post"}`}
                                                        className={cn(
                                                            "truncate rounded-md px-1.5 py-0.5 text-[11px] font-semibold",
                                                            postStatusChipClasses[
                                                                post.status
                                                                    .value as PostStatusValue
                                                            ] ??
                                                                FALLBACK_STATUS_CHIP_CLASS,
                                                        )}
                                                    >
                                                        {post.caption ||
                                                            "Untitled post"}
                                                    </span>
                                                ))}
                                            {dayPosts.length > 2 && (
                                                <span className="px-1.5 text-[10px] font-semibold text-(--muted)">
                                                    +{dayPosts.length - 2} more
                                                </span>
                                            )}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </section>

                    <aside className="rounded-2xl border border-(--border) bg-(--panel) p-5 lg:sticky lg:top-8">
                        {effectiveSelectedDay === null ? (
                            <div className="text-center">
                                <span className="mx-auto grid size-11 place-items-center rounded-full bg-(--panel-muted) text-(--muted)">
                                    <CalendarPlus
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <p className="mt-3 text-sm font-semibold text-(--text)">
                                    Select a day
                                </p>
                                <p className="mt-1 text-xs text-(--muted)">
                                    Pick a date on the calendar to see and
                                    manage its posts.
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <h2 className="text-base font-bold text-(--text)">
                                            {effectiveSelectedDay.toLocaleDateString(
                                                undefined,
                                                {
                                                    weekday: "long",
                                                    month: "long",
                                                    day: "numeric",
                                                },
                                            )}
                                        </h2>
                                        <p className="mt-0.5 text-xs text-(--muted)">
                                            {selectedPosts.length === 0
                                                ? "No posts on this day"
                                                : `${selectedPosts.length} post${selectedPosts.length === 1 ? "" : "s"}`}
                                        </p>
                                    </div>
                                    <Link
                                        href={
                                            PostController.create({
                                                workspace:
                                                    currentWorkspace.slug,
                                            }).url
                                        }
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-linear-to-r from-[var(--color-accent-start)] to-[var(--color-accent-end)] px-3 py-2 text-xs font-bold text-[var(--color-accent-ink)] shadow-sm shadow-[color:var(--color-accent-end)]/20 transition hover:-translate-y-0.5 hover:brightness-105 focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 focus:outline-none"
                                    >
                                        <Plus
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                        New post
                                    </Link>
                                </div>

                                {selectedPosts.length === 0 ? (
                                    <p className="mt-4 rounded-xl border border-dashed border-(--border) bg-(--panel-muted) p-5 text-center text-sm text-(--muted)">
                                        Nothing on this day yet — create one
                                        with the button above.
                                    </p>
                                ) : (
                                    <ul className="mt-4 grid gap-3">
                                        {selectedPosts.map((post) => (
                                            <li
                                                key={post.id}
                                                className="rounded-xl border border-(--border) p-3.5"
                                            >
                                                <div className="flex items-start gap-3">
                                                    {post.media[0] ? (
                                                        <img
                                                            src={
                                                                post.media[0]
                                                                    .url
                                                            }
                                                            alt=""
                                                            className="grid size-12 shrink-0 place-items-center rounded-lg border border-(--border) object-cover"
                                                        />
                                                    ) : (
                                                        <span className="grid size-12 shrink-0 place-items-center rounded-lg bg-(--panel-muted) text-(--muted)">
                                                            <FileText
                                                                className="size-5"
                                                                aria-hidden="true"
                                                            />
                                                        </span>
                                                    )}
                                                    <div className="min-w-0 flex-1">
                                                        <p className="line-clamp-2 text-sm font-semibold text-(--text)">
                                                            {post.caption ||
                                                                "Untitled post"}
                                                        </p>
                                                        <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                                            <span
                                                                className={cn(
                                                                    "rounded-full px-2.5 py-0.5 text-xs font-semibold",
                                                                    postStatusChipClasses[
                                                                        post
                                                                            .status
                                                                            .value as PostStatusValue
                                                                    ] ??
                                                                        FALLBACK_STATUS_CHIP_CLASS,
                                                                )}
                                                            >
                                                                {
                                                                    post.status
                                                                        .label
                                                                }
                                                            </span>
                                                            <span className="inline-flex items-center gap-1 text-xs font-medium text-(--muted)">
                                                                <Clock
                                                                    className="size-3"
                                                                    aria-hidden="true"
                                                                />
                                                                {formatPostTime(
                                                                    post,
                                                                )}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                                                    <div className="flex -space-x-1.5">
                                                        {post.targets.map(
                                                            (target) => {
                                                                const brand =
                                                                    platformBrands[
                                                                        target
                                                                            .platform
                                                                            .value as PlatformValue
                                                                    ];

                                                                if (!brand) {
                                                                    return null;
                                                                }

                                                                const Icon =
                                                                    brand.icon;

                                                                return (
                                                                    <span
                                                                        key={
                                                                            target.id
                                                                        }
                                                                        title={`${target.display_name} · ${target.platform.label}`}
                                                                        className="grid size-6 place-items-center rounded-full border-2 border-(--panel) bg-(--panel-muted) text-(--muted)"
                                                                    >
                                                                        <Icon className="size-3" />
                                                                    </span>
                                                                );
                                                            },
                                                        )}
                                                    </div>
                                                    {post.status.value ===
                                                        "scheduled" && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setRescheduling(
                                                                    post,
                                                                )
                                                            }
                                                            className="rounded-lg border border-(--border) px-3 py-1.5 text-xs font-semibold text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text) focus:ring-4 focus:ring-[color:var(--color-line)]/40 focus:outline-none"
                                                        >
                                                            Move
                                                        </button>
                                                    )}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </>
                        )}
                    </aside>
                </div>
            </div>

            {rescheduling !== null && (
                <RescheduleModal
                    workspaceSlug={currentWorkspace.slug}
                    post={rescheduling}
                    onClose={() => setRescheduling(null)}
                />
            )}
        </AuthenticatedLayout>
    );
}
