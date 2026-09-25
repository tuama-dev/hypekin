import { AlertTriangle } from "lucide-react";
import { useEffect, useId, useRef, useState } from "react";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

interface ConfirmDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    confirmText?: string;
    cancelText?: string;
    variant?: "danger" | "accent";
    onConfirm: () => void;
    onCancel?: () => void;
    children?: ReactNode;
}

export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmText = "Confirm",
    cancelText = "Cancel",
    variant = "danger",
    onConfirm,
    onCancel,
    children,
}: ConfirmDialogProps) {
    const headingId = useId();
    const confirmButtonRef = useRef<HTMLButtonElement>(null);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        document.body.style.overflow = "hidden";

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === "Escape") {
                event.stopPropagation();
                onOpenChange(false);
            }
        };

        document.addEventListener("keydown", onKeyDown);
        confirmButtonRef.current?.focus();

        return () => {
            document.body.style.overflow = "";
            document.removeEventListener("keydown", onKeyDown);
        };
    }, [open, onOpenChange]);

    if (!open) {
        return null;
    }

    function close() {
        onCancel?.();
        onOpenChange(false);
    }

    async function handleConfirm() {
        setProcessing(true);

        try {
            await onConfirm();
        } finally {
            setProcessing(false);
            onOpenChange(false);
        }
    }

    return (
        <div
            className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby={headingId}
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    close();
                }
            }}
        >
            <div className="w-full max-w-md rounded-2xl border border-(--border) bg-(--panel) p-6 shadow-2xl">
                <div className="flex items-start gap-4">
                    <span
                        className={cn(
                            "grid size-11 shrink-0 place-items-center rounded-xl",
                            variant === "danger"
                                ? "bg-red-500/10 text-red-600"
                                : "bg-[var(--color-accent-start)]/10 text-[var(--color-accent-start)]",
                        )}
                    >
                        <AlertTriangle className="size-5" aria-hidden="true" />
                    </span>

                    <div className="min-w-0 flex-1">
                        <h2
                            id={headingId}
                            className="text-base font-bold text-(--text)"
                        >
                            {title}
                        </h2>
                        {description !== undefined && (
                            <p className="mt-1 text-sm leading-6 text-(--muted)">
                                {description}
                            </p>
                        )}
                        {children !== undefined && (
                            <div className="mt-2">{children}</div>
                        )}
                    </div>
                </div>

                <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={close}
                        className="shrink-0 rounded-lg border border-(--border) px-4 py-2 text-sm font-semibold text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text) focus:outline-none focus:ring-4 focus:ring-[color:var(--color-line)]/40"
                    >
                        {cancelText}
                    </button>
                    <button
                        ref={confirmButtonRef}
                        type="button"
                        onClick={handleConfirm}
                        disabled={processing}
                        className={cn(
                            "shrink-0 rounded-lg px-4 py-2 text-sm font-bold transition focus:outline-none focus:ring-4 disabled:cursor-not-allowed disabled:opacity-60",
                            variant === "danger"
                                ? "bg-red-600 text-white shadow-md shadow-red-600/20 hover:bg-red-700 focus:ring-red-600/25"
                                : "bg-linear-to-r from-[var(--color-accent-start)] to-[var(--color-accent-end)] text-[var(--color-accent-ink)] shadow-md shadow-[color:var(--color-accent-end)]/20 hover:brightness-105 focus:ring-[color:var(--color-accent-start)]/25",
                        )}
                    >
                        {processing ? "Please wait..." : confirmText}
                    </button>
                </div>
            </div>
        </div>
    );
}