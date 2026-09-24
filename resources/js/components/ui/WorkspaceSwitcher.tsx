import { Link, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { Check, ChevronDown } from "lucide-react";
import { index as dashboardIndex } from "@/actions/App/Http/Controllers/Application/DashboardController";

interface WorkspaceSwitcherProps {
    isCollapsed: boolean;
}

export default function WorkspaceSwitcher({ isCollapsed }: WorkspaceSwitcherProps) {
    const { auth } = usePage().props;
    const [isOpen, setIsOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    const current = auth.workspace;
    const workspaces = auth.workspaces ?? [];

    useEffect(() => {
        function handlePointerDown(event: PointerEvent) {
            if (!containerRef.current?.contains(event.target as Node)) {
                setIsOpen(false);
            }
        }

        function handleKeyDown(event: KeyboardEvent) {
            if (event.key === "Escape") {
                setIsOpen(false);
            }
        }

        document.addEventListener("pointerdown", handlePointerDown);
        document.addEventListener("keydown", handleKeyDown);
        return () => {
            document.removeEventListener("pointerdown", handlePointerDown);
            document.removeEventListener("keydown", handleKeyDown);
        };
    }, []);

    const initial = current?.name.trim().charAt(0).toUpperCase() ?? "W";

    return (
        <div className="min-w-0 flex-1" ref={containerRef}>
            <button
                type="button"
                className={`flex w-full items-center gap-2.5 rounded-lg py-1.5 text-(--text) transition hover:bg-(--panel-muted) ${
                    isCollapsed ? "justify-center px-1" : "px-1.5"
                }`}
                onClick={() => setIsOpen((value) => !value)}
                aria-expanded={isOpen}
                aria-haspopup="menu"
                aria-label="Switch workspace"
            >
                <span className="grid size-8 flex-shrink-0 place-items-center rounded-lg bg-linear-to-br from-[var(--color-accent-start)] to-[var(--color-accent-end)] text-xs font-extrabold text-white">
                    {initial}
                </span>
                {!isCollapsed && (
                    <>
                        <span className="min-w-0 flex-1 truncate text-start text-sm font-semibold">
                            {current?.name ?? "Workspace"}
                        </span>
                        <ChevronDown
                            className={`size-4 flex-shrink-0 text-(--muted) transition-transform ${
                                isOpen ? "rotate-180" : ""
                            }`}
                        />
                    </>
                )}
            </button>

            {isOpen && (
                <div className="absolute left-0 top-full z-50 mt-2 w-72 rounded-xl border border-(--surface-border) bg-(--surface) p-1.5 shadow-(--surface-shadow)">
                    <p className="px-3 pb-1 pt-2 text-xs font-semibold tracking-wide text-(--muted) uppercase">
                        Workspaces
                    </p>
                    {workspaces.map((workspace) => {
                        const isActive = workspace.slug === current?.slug;

                        return (
                            <Link
                                key={workspace.id}
                                href={dashboardIndex({ workspace: workspace.slug }).url}
                                onClick={() => setIsOpen(false)}
                                className="flex items-center gap-2.5 rounded-lg px-2.5 py-2 transition hover:bg-(--panel-muted)"
                            >
                                <span className="grid size-7 flex-shrink-0 place-items-center rounded-md bg-(--panel-muted) text-xs font-bold text-(--muted)">
                                    {workspace.name.trim().charAt(0).toUpperCase()}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-semibold text-(--text)">
                                        {workspace.name}
                                    </span>
                                    <span className="block text-xs capitalize text-(--muted)">
                                        {workspace.role}
                                    </span>
                                </span>
                                {isActive && <Check className="size-4 text-[var(--color-primary)]" />}
                            </Link>
                        );
                    })}
                </div>
            )}
        </div>
    );
}