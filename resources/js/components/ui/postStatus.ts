export type PostStatusValue =
    | "draft"
    | "scheduled"
    | "publishing"
    | "published"
    | "failed"
    | "canceled";

export type PostTargetStatusValue =
    | "pending"
    | "queued"
    | "published"
    | "failed";

export const FALLBACK_STATUS_CHIP_CLASS = "bg-neutral-500/10 text-neutral-500";

export const FALLBACK_STATUS_SWATCH_CLASS = "bg-neutral-400";

/**
 * Tinted pill classes for a post status chip.
 *
 * Every status gets its own hue so the calendar legend, the posts list and the
 * day panel stay distinguishable: green = published, amber = in flight, blue =
 * scheduled, red = failed, grey = draft, neutral = canceled.
 */
export const postStatusChipClasses: Record<PostStatusValue, string> = {
    published: "bg-emerald-500/10 text-emerald-600",
    publishing: "bg-amber-500/10 text-amber-600",
    scheduled: "bg-sky-500/10 text-sky-600",
    failed: "bg-rose-500/10 text-rose-600",
    draft: "bg-slate-500/10 text-slate-500",
    canceled: "bg-neutral-500/10 text-neutral-600",
};

/**
 * Solid swatch classes matching the post status hues, for legend dots.
 */
export const postStatusSwatchClasses: Record<PostStatusValue, string> = {
    published: "bg-emerald-500",
    publishing: "bg-amber-500",
    scheduled: "bg-sky-500",
    failed: "bg-rose-500",
    draft: "bg-slate-400",
    canceled: "bg-neutral-500",
};

export const postTargetStatusChipClasses: Record<
    PostTargetStatusValue,
    string
> = {
    pending: "bg-slate-500/10 text-slate-500",
    queued: "bg-sky-500/10 text-sky-600",
    published: "bg-emerald-500/10 text-emerald-600",
    failed: "bg-rose-500/10 text-rose-600",
};

/**
 * Legend entries for the post status colors, ordered along the publishing
 * pipeline from the furthest along to the earliest.
 */
export const postStatusLegend: { value: PostStatusValue; label: string }[] = [
    { value: "published", label: "Published" },
    { value: "publishing", label: "Publishing" },
    { value: "scheduled", label: "Scheduled" },
    { value: "failed", label: "Failed" },
    { value: "draft", label: "Draft" },
    { value: "canceled", label: "Canceled" },
];
