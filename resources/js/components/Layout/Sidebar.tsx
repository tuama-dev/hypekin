import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    ChevronRight,
    ChevronUp,
    LogOut,
    Settings,
    User,
    X,
} from 'lucide-react';

import { SidebarItem } from '@/config/navigation/application';
import { navigation, isPathActive } from '@/config/navigation/application';
import AuthController from '@/actions/App/Http/Controllers/Application/Auth/AuthController';
import WorkspaceSwitcher from '@/components/ui/WorkspaceSwitcher';

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
    currentPath: string;
    onNavigate: () => void;
}

function SidebarMenuItem({
    item,
    depth,
    isCollapsed,
    workspaceSlug,
    currentPath,
    onNavigate,
}: SidebarMenuItemProps) {
    const [isOpen, setIsOpen] = useState(false);

    const hasChildren = Boolean(item.children?.length);
    const indent = depth > 0 ? 40 + (depth - 1) * 16 : undefined;
    const itemHref = item.href
        ? `/app/${workspaceSlug ?? ''}${item.href}`
        : undefined;
    const isActive =
        itemHref !== undefined && isPathActive(itemHref, currentPath);

    const labelClass = `truncate overflow-hidden whitespace-nowrap transition-[max-width,opacity] duration-300 ${
        isCollapsed ? 'max-w-0 opacity-0' : 'max-w-32 opacity-100'
    }`;

    const itemClass = `flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition hover:bg-(--panel-muted) hover:text-(--text) ${
        isActive ? 'bg-(--panel-muted) text-(--text)' : 'text-(--muted)'
    }`;

    const padding = indent === undefined ? undefined : { paddingLeft: indent };

    if (!hasChildren) {
        return (
            <Link
                href={itemHref ?? '#'}
                onClick={onNavigate}
                aria-current={isActive ? 'page' : undefined}
                className={itemClass}
                style={padding}
            >
                {item.icon ? (
                    <item.icon
                        width={18}
                        className={`flex-shrink-0 ${
                            isActive ? 'text-[var(--color-accent-start)]' : ''
                        }`}
                    />
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
                    <item.icon
                        width={18}
                        className={`flex-shrink-0 ${
                            isActive ? 'text-[var(--color-accent-start)]' : ''
                        }`}
                    />
                ) : null}
                <span className={labelClass}>{item.label}</span>
                {!isCollapsed && (
                    <ChevronRight
                        width={18}
                        className={`ml-auto flex-shrink-0 transition-transform duration-300 ${
                            isOpen ? 'rotate-90' : ''
                        }`}
                    />
                )}
            </button>
            {!isCollapsed && (
                <div
                    className={`grid transition-[grid-template-rows] duration-300 ${
                        isOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'
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
                                currentPath={currentPath}
                                onNavigate={onNavigate}
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

interface SidebarAccountMenuProps {
    isCollapsed: boolean;
    onNavigate: () => void;
}

function SidebarAccountMenu({
    isCollapsed,
    onNavigate,
}: SidebarAccountMenuProps) {
    const { props } = usePage();
    const [isOpen, setIsOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);

    const fullname = props.auth?.user.fullname ?? '';
    const initials =
        fullname
            .split(' ')
            .map((part) => part[0])
            .join('') || 'C';
    const firstName = fullname.split(' ')[0] || 'Account';
    const workspaceSlug = props.auth?.workspace?.slug ?? null;
    const settingsHref = `/app/${workspaceSlug}/settings`;

    useEffect(() => {
        function handlePointerDown(event: PointerEvent) {
            if (!menuRef.current?.contains(event.target as Node)) {
                setIsOpen(false);
            }
        }

        function handleKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setIsOpen(false);
            }
        }

        document.addEventListener('pointerdown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.removeEventListener('pointerdown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, []);

    function handleLogout() {
        setIsOpen(false);
        router.post(AuthController.logout().url);
    }

    return (
        <div ref={menuRef} className="relative border-t border-(--border) p-3">
            <button
                type="button"
                onClick={() => setIsOpen((current) => !current)}
                aria-haspopup="menu"
                aria-expanded={isOpen}
                title={isCollapsed ? firstName : undefined}
                className={`flex w-full items-center gap-3 rounded-lg py-2 text-sm font-semibold text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text) ${
                    isCollapsed ? 'justify-center px-0' : 'px-3'
                }`}
            >
                <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[#343a4b] text-xs font-bold text-white">
                    {initials}
                </span>
                {!isCollapsed && (
                    <>
                        <span className="truncate">{firstName}</span>
                        <ChevronUp
                            width={18}
                            className={`ml-auto shrink-0 transition-transform duration-300 ${
                                isOpen ? 'rotate-180' : ''
                            }`}
                        />
                    </>
                )}
            </button>
            {isOpen && (
                <div
                    role="menu"
                    className={`absolute bottom-full z-50 mb-2 rounded-lg border border-(--surface-border) bg-(--surface) p-1 shadow-(--surface-shadow) ${
                        isCollapsed
                            ? 'left-1/2 w-44 -translate-x-1/2'
                            : 'inset-x-0'
                    }`}
                >
                    <Link
                        href="/profile"
                        role="menuitem"
                        onClick={onNavigate}
                        className="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-(--text) transition hover:bg-(--panel-muted)"
                    >
                        <User className="size-4 text-(--muted)" />
                        <span>Profile</span>
                    </Link>
                    <Link
                        href={settingsHref}
                        role="menuitem"
                        onClick={onNavigate}
                        className="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-(--text) transition hover:bg-(--panel-muted)"
                    >
                        <Settings className="size-4 text-(--muted)" />
                        <span>Settings</span>
                    </Link>
                    <div className="my-1 border-t border-(--border)" />
                    <button
                        type="button"
                        role="menuitem"
                        onClick={handleLogout}
                        className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-(--text) transition hover:bg-(--panel-muted)"
                    >
                        <LogOut className="size-4 text-(--muted)" />
                        <span>Logout</span>
                    </button>
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
    const { props, url } = usePage();
    const workspaceSlug = props.auth.workspace?.slug ?? null;
    const currentPath = url.split('?')[0].replace(/\/+$/, '');

    return (
        <>
            <div
                className={`fixed inset-0 z-30 bg-black/50 transition-opacity duration-300 lg:hidden ${
                    isOpen ? 'opacity-100' : 'pointer-events-none opacity-0'
                }`}
                onClick={onClose}
                aria-hidden="true"
            />
            <aside
                className={`fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-(--border) bg-(--panel) transition-[width,transform] duration-300 ease-out will-change-transform lg:sticky lg:top-0 lg:h-svh lg:translate-x-0 ${
                    isCollapsed ? 'lg:w-20' : 'lg:w-72'
                } ${isOpen ? 'translate-x-0' : '-translate-x-full'}`}
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
                            currentPath={currentPath}
                            onNavigate={onClose}
                        />
                    ))}
                </nav>
                <SidebarAccountMenu
                    isCollapsed={isCollapsed}
                    onNavigate={onClose}
                />
            </aside>
        </>
    );
}
