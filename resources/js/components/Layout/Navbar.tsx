import { usePage } from '@inertiajs/react';
import { Bell, Menu, PanelLeftClose, PanelRightClose } from 'lucide-react';
import { ThemeToggle } from '@/components/ui/ThemeToggle';
import { getActiveItem } from '@/config/navigation/application';

interface NavbarProps {
    isSidebarCollapsed: boolean;
    isSidebarOpen: boolean;
    onToggleSidebar: () => void;
}

export default function Navbar({
    isSidebarCollapsed,
    isSidebarOpen,
    onToggleSidebar,
}: NavbarProps) {
    const { props, url } = usePage();
    const workspaceSlug = props.auth?.workspace?.slug ?? null;
    const currentPath = url.split('?')[0].replace(/\/+$/, '');
    const activeItem = getActiveItem(currentPath, workspaceSlug);

    return (
        <header className="flex h-[4.25rem] items-center justify-between border-b border-(--border) bg-(--panel) px-4 shadow-[0_8px_24px_-24px_rgba(43,63,104,0.45)] sm:px-6">
            <div className="flex min-w-0 items-center gap-3">
                <button
                    className="grid size-9 shrink-0 place-items-center rounded-lg text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text)"
                    type="button"
                    onClick={onToggleSidebar}
                    aria-label="Toggle sidebar"
                    aria-expanded={isSidebarOpen || !isSidebarCollapsed}
                >
                    <Menu width={17} className="text-xl lg:hidden" />
                    {isSidebarCollapsed ? (
                        <PanelRightClose
                            width={17}
                            className="hidden lg:block"
                        />
                    ) : (
                        <PanelLeftClose
                            width={17}
                            className="hidden lg:block"
                        />
                    )}
                </button>
                {activeItem && (
                    <span className="hidden truncate text-sm font-semibold text-(--text) sm:block">
                        {activeItem.label}
                    </span>
                )}
            </div>
            <div className="ml-auto flex items-center gap-2 sm:gap-4">
                <button
                    className="grid size-9 place-items-center rounded-lg text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text)"
                    type="button"
                    aria-label="Notifications"
                >
                    <Bell />
                </button>
                <ThemeToggle />
            </div>
        </header>
    );
}
