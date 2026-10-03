import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface SectionCardProps {
    title: string;
    subtitle?: string;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
}

export function SectionCard({
    title,
    subtitle,
    action,
    className,
    children,
}: SectionCardProps) {
    return (
        <section
            className={cn(
                'rounded-2xl border border-(--border) bg-(--panel)',
                className,
            )}
        >
            <header className="flex items-center justify-between gap-3 border-b border-(--border) px-4 py-3">
                <div>
                    <h3 className="text-base font-extrabold tracking-tight text-(--text)">
                        {title}
                    </h3>
                    {subtitle && (
                        <p className="mt-0.5 text-xs text-(--muted)">
                            {subtitle}
                        </p>
                    )}
                </div>
                {action}
            </header>
            <div className="p-4">{children}</div>
        </section>
    );
}
