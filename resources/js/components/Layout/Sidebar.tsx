import { Link, usePage } from "@inertiajs/react";
import { useState } from "react";
import { ChevronRight, X } from "lucide-react";

import { SidebarItem } from "@/config/navigation/application";
import { navigation } from "@/config/navigation/application";
import WorkspaceSwitcher from "@/components/ui/WorkspaceSwitcher";

interface SidebarProps {
    isCollapsed: boolean;
    isOpen: boolean;
    onClose: () => void;
}

interface SidebarMenuItemProps {
    item: SidebarItem;
    depth: number;
    isCollapsed: boolean;
    workspaceSlug: string | null;
    onNavigate: () => void;
}

function SidebarMenuItem({
    item,
    depth,
    isCollapsed,
    workspaceSlug,
    onNavigate,
}: SidebarMenuItemProps) {
    const [isOpen, setIsOpen] = useState(false);

    const hasChildren = Boolean(item.children?.length);
    const indent = depth > 0 ? 40 + (depth - 1) * 16 : undefined;
    const itemHref = item.href
        ? `/app/${workspaceSlug ?? ""}${item.href}`
        : undefined;

    const labelClass = `truncate overflow-hidden whitespace-nowrap transition-[max-width,opacity] duration-300 ${
        isCollapsed ? "max-w-0 opacity-0" : "max-w-32 opacity-100"
    }`;

    const itemClass =
        "flex items-center gap-3 rounded-lg px-3 py-2.5 text-base font-semibold text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text)";

    const padding = indent === undefined ? undefined : { paddingLeft: indent };

    if (!hasChildren) {
        return (
            <Link
                href={itemHref ?? "#"}
                onClick={onNavigate}
                className={itemClass}
                style={padding}
            >
                {item.icon ? (
                    <item.icon width={20} className="flex-shrink-0" />
                ) : null}
                <span className={labelClass}>{item.label}</span>
            </Link>
        );
    }

    return (
        <div>
            <button
                type="button"
                onClick={() => setIsOpen((current) => !current)}
                aria-expanded={isOpen}
                className={`${itemClass} w-full`}
                style={padding}
            >
                {item.icon ? (
                    <item.icon width={20} className="flex-shrink-0" />
                ) : null}
                <span className={labelClass}>{item.label}</span>
                {!isCollapsed && (
                    <ChevronRight
                        width={18}
                        className={`ml-auto flex-shrink-0 transition-transform duration-300 ${
                            isOpen ? "rotate-90" : ""
                        }`}
                    />
                )}
            </button>
            {!isCollapsed && (
                <div
                    className={`grid transition-[grid-template-rows] duration-300 ${
                        isOpen ? "grid-rows-[1fr]" : "grid-rows-[0fr]"
                    }`}
                >
                    <div className="flex min-h-0 flex-col gap-1 overflow-hidden">
                        {item.children?.map((child) => (
                            <SidebarMenuItem
                                key={child.label}
                                item={child}
                                depth={depth + 1}
                                isCollapsed={isCollapsed}
                                workspaceSlug={workspaceSlug}
                                onNavigate={onNavigate}
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

export default function Sidebar({
    isCollapsed,
    isOpen,
    onClose,
}: SidebarProps) {
    const workspaceSlug = usePage().props.auth.workspace?.slug ?? null;

    return (
        <>
            <div
                className={`fixed inset-0 z-30 bg-black/50 transition-opacity duration-300 lg:hidden ${
                    isOpen ? "opacity-100" : "pointer-events-none opacity-0"
                }`}
                onClick={onClose}
                aria-hidden="true"
            />
            <aside
                className={`fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-(--border) bg-(--panel) transition-[width,transform] duration-300 ease-out will-change-transform lg:sticky lg:top-0 lg:h-svh lg:translate-x-0 ${
                    isCollapsed ? "lg:w-20" : "lg:w-72"
                } ${isOpen ? "translate-x-0" : "-translate-x-full"}`}
            >
                <div className="relative flex h-[4.25rem] items-center justify-between gap-3 border-b border-(--border) px-4">
                    <WorkspaceSwitcher isCollapsed={isCollapsed} />
                    <button
                        className="grid size-8 shrink-0 place-items-center rounded-lg text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text) lg:hidden"
                        type="button"
                        onClick={onClose}
                        aria-label="Close sidebar"
                    >
                        <X width={18} />
                    </button>
                </div>
                <nav className="flex-1 space-y-1 overflow-y-auto p-3">
                    {navigation.map((item) => (
                        <SidebarMenuItem
                            key={item.label}
                            item={item}
                            depth={0}
                            isCollapsed={isCollapsed}
                            workspaceSlug={workspaceSlug}
                            onNavigate={onClose}
                        />
                    ))}
                </nav>
            </aside>
        </>
    );
}
