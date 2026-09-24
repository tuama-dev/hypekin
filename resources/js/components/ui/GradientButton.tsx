import type { ButtonHTMLAttributes } from 'react';

export function GradientButton({ children, className = '', ...props }: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button
            {...props}
            className={`h-13 w-full rounded-xl bg-linear-to-r from-[var(--color-accent-start)] to-[var(--color-accent-end)] px-5 text-sm font-bold text-[var(--color-accent-ink)] shadow-lg shadow-[color:var(--color-accent-end)]/20 transition hover:-translate-y-0.5 hover:brightness-105 focus:outline-none focus:ring-4 focus:ring-[color:var(--color-accent-start)]/25 disabled:cursor-not-allowed disabled:opacity-60 ${className}`}
        >
            {children}
        </button>
    );
}