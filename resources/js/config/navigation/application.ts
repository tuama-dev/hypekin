import type { LucideIcon } from 'lucide-react';
import { LayoutDashboard, Link2, Send, Settings } from 'lucide-react';

export type SidebarItem = {
    label: string;
    href?: string;
    icon?: LucideIcon;
    children?: SidebarItem[];
};

export const navigation: SidebarItem[] = [
    { label: 'Dashboard', icon: LayoutDashboard, href: '/dashboard' },
    { label: 'Posts', icon: Send, href: '/posts' },
    { label: 'Accounts', icon: Link2, href: '/accounts' },
];

export const footerNavigation: SidebarItem[] = [
    { label: 'Settings', icon: Settings, href: '/settings' },
];

export function isPathActive(href: string, currentPath: string): boolean {
    if (currentPath === '' || currentPath === href) {
        return currentPath === href;
    }

    return currentPath.startsWith(href + '/');
}

export function getActiveItem(
    currentPath: string,
    workspaceSlug: string | null,
): SidebarItem | null {
    const all = [...navigation, ...footerNavigation];

    return (
        all.find((item) => {
            if (item.href === undefined) {
                return false;
            }

            const href = `/app/${workspaceSlug ?? ''}${item.href}`;

            return isPathActive(href, currentPath);
        }) ?? null
    );
}
