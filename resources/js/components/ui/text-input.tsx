import type { InputHTMLAttributes } from "react";
import { AtSign, LockKeyhole, LucideIcon, Mail } from "lucide-react";

type TextInputProps = InputHTMLAttributes<HTMLInputElement> & {
    label: string;
    error?: string;
    icon?: LucideIcon;
};

export function TextInput({
    id,
    label,
    error,
    className = "",
    icon: Icon,
    ...props
}: TextInputProps) {
    const inputId = id ?? props.name;

    return (
        <div className="grid gap-2.5">
            <label
                className="text-sm font-bold text-[var(--color-ink)]"
                htmlFor={inputId}
            >
                {label}
                {props.required && (
                    <span className="ml-1 text-rose-400">*</span>
                )}
            </label>
            <div className="relative">
                <span
                    className="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[var(--color-muted)]"
                    aria-hidden="true"
                >
                    {Icon && <Icon width={17} strokeWidth={2}></Icon>}
                </span>
                <input
                    {...props}
                    id={inputId}
                    className={`h-13 w-full rounded-xl border bg-[var(--color-panel-muted)] px-4 pl-11 text-sm text-[var(--color-ink)] outline-none transition placeholder:text-[var(--color-muted)]/75 focus:border-[var(--color-accent-start)] focus:bg-[var(--color-panel)] focus:ring-4 focus:ring-[color:var(--color-accent-start)]/15 ${className}`}
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? `${inputId}-error` : undefined}
                />
            </div>
            {error && (
                <p id={`${inputId}-error`} className="text-sm text-rose-400">
                    {error}
                </p>
            )}
        </div>
    );
}
