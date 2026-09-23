import { useEffect, useRef, useState } from "react";
import { Link, router, usePage } from "@inertiajs/react";
import {
    Bell,
    ChevronDown,
    PanelLeftClose,
    PanelRightClose,
    LogOut,
    Menu,
    User,
} from "lucide-react";
import { ThemeToggle } from "@/components/ui/theme-toggle";
import AuthController from "@/actions/App/Http/Controllers/Application/Auth/AuthController";

const menuIcons = {
    user: User,
    logout: LogOut,
} as const;

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
    const [isUserMenuOpen, setIsUserMenuOpen] = useState(false);
    const userMenuRef = useRef<HTMLDivElement>(null);
    const { props } = usePage();

    const dropDown = {
        initials:
            props.auth?.user.fullname
                ?.split(" ")
                .map((n: string) => n[0])
                .join("") || "C",
        displayName: props.auth?.user.fullname
            ? props.auth.user.fullname.split(" ")[0]
            : "Customer",
        menuItems: [
            { label: "Profile", href: "/profile", icon: "user" },
            { label: "Logout", href: "", icon: "logout" },
        ],
    } as const;

    useEffect(() => {
        function handlePointerDown(event: PointerEvent) {
            if (!userMenuRef.current?.contains(event.target as Node)) {
                setIsUserMenuOpen(false);
            }
        }

        function handleKeyDown(event: KeyboardEvent) {
            if (event.key === "Escape") {
                setIsUserMenuOpen(false);
            }
        }

        document.addEventListener("pointerdown", handlePointerDown);
        document.addEventListener("keydown", handleKeyDown);
        return () => {
            document.removeEventListener("pointerdown", handlePointerDown);
            document.removeEventListener("keydown", handleKeyDown);
        };
    }, []);

    const handleLogout = () => {
        setIsUserMenuOpen(false);
        router.post(AuthController.logout().url);
    };

    return (
        <header className="flex h-[4.25rem] items-center justify-between border-b border-(--border) bg-(--panel) px-4 shadow-[0_8px_24px_-24px_rgba(43,63,104,0.45)] sm:px-6">
            <button
                className="grid size-9 place-items-center rounded-lg text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text)"
                type="button"
                onClick={onToggleSidebar}
                aria-label="Toggle sidebar"
                aria-expanded={isSidebarOpen || !isSidebarCollapsed}
            >
                <Menu width={17} className="text-xl lg:hidden" />
                {isSidebarCollapsed ? (
                    <PanelRightClose width={17} className="hidden lg:block" />
                ) : (
                    <PanelLeftClose width={17} className="hidden lg:block" />
                )}
            </button>
            <div className="ml-auto flex items-center gap-2 sm:gap-4">
                <button
                    className="grid size-9 place-items-center rounded-lg text-(--muted) transition hover:bg-(--panel-muted) hover:text-(--text)"
                    type="button"
                    aria-label="Notifications"
                >
                    <Bell />
                </button>
                <ThemeToggle />
                <div className="relative" ref={userMenuRef}>
                    <button
                        className="flex items-center gap-2 rounded-xl p-1.5 text-sm font-semibold text-(--text) transition hover:bg-(--panel-muted)"
                        type="button"
                        onClick={() => setIsUserMenuOpen((current) => !current)}
                        aria-expanded={isUserMenuOpen}
                        aria-haspopup="menu"
                    >
                        <span className="grid size-8 place-items-center rounded-full bg-[#343a4b] text-xs font-bold text-white">
                            {dropDown.initials}
                        </span>
                        <span className="hidden sm:inline">
                            {dropDown.displayName}
                        </span>
                        <ChevronDown
                            className={`text-xs text-(--muted) transition-transform ${
                                isUserMenuOpen ? "rotate-180" : ""
                            }`}
                        />
                    </button>
                    {isUserMenuOpen && (
                        <div
                            className="absolute top-full right-0 z-50 mt-2 min-w-44 rounded-lg border border-(--surface-border) bg-(--surface) p-1 shadow-(--surface-shadow)"
                            role="menu"
                        >
                            {dropDown.menuItems.map((item) => {
                                const MenuIcon = menuIcons[item.icon];

                                return (
                                    <Link
                                        className="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-(--text) transition hover:bg-(--panel-muted)"
                                        href={item.href}
                                        key={item.label}
                                        onClick={() => {
                                            setIsUserMenuOpen(false);
                                            if (item.icon === "logout") {
                                                handleLogout();
                                            }
                                        }}
                                        role="menuitem"
                                    >
                                        <MenuIcon className="size-4 text-(--muted)" />
                                        <span>{item.label}</span>
                                    </Link>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}
