import type { LucideIcon } from 'lucide-react';
import { LayoutDashboard, Settings } from 'lucide-react';

export type SidebarItem = {
    label: string;
    href?: string;
    icon?: LucideIcon;
    children?: SidebarItem[];
};

export const navigation: SidebarItem[] = [
    { label: 'Dashboard', icon: LayoutDashboard, href: '/dashboard' },
    { label: 'Settings', icon: Settings, href: '/settings' },
];
