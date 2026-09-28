import { Link } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import PostController from '@/actions/App/Http/Controllers/Application/PostController';
import { platformBrands, type PlatformValue } from '@/components/ui/platformBrands';
import { timeUntil } from '@/lib/timeAgo';
import { cn } from '@/lib/utils';
import type { UpcomingPost } from './types';

interface UpcomingScheduleProps {
    workspaceSlug: string;
    items: UpcomingPost[];
}

export function UpcomingSchedule({ workspaceSlug, items }: UpcomingScheduleProps) {
    if (items.length === 0) {
        return (
            <div className="flex items-center gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-(--panel-muted) text-(--muted)">
                    <CalendarClock className="size-5" />
                </span>
                <p className="text-sm text-(--muted)">No scheduled posts coming up.</p>
            </div>
        );
    }

    return (
        <ul className="divide-y divide-(--border)">
            {items.map((item) => (
                <li key={item.id} className="py-2">
                    <Link
                        href={PostController.show({ workspace: workspaceSlug, post: item.id }).url}
                        className="group flex items-center gap-3 rounded-xl px-1 py-1 transition hover:bg-(--panel-muted) -mx-1"
                    >
                        <div className="flex shrink-0 -space-x-1.5">
                            {item.platforms.slice(0, 3).map((platform) => {
                                const brand = platformBrands[platform as PlatformValue];

                                return brand ? (
                                    <span
                                        key={platform}
                                        className={cn(
                                            'grid size-7 place-items-center rounded-full border-2 border-(--panel) text-white',
                                            brand.buttonClass,
                                        )}
                                    >
                                        <brand.icon className="size-3.5" />
                                    </span>
                                ) : null;
                            })}
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-bold text-(--text)">
                                {item.title ?? item.caption}
                            </p>
                            <p className="text-xs text-(--muted)">
                                {timeUntil(item.scheduled_at)}
                            </p>
                        </div>
                        <span className="shrink-0 text-xs font-bold text-(--color-accent-start)">
                            View
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}