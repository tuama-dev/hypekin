import { SidebarItem } from '@/config/navigation/application';
import { Activity, HeartPulse, PanelsTopLeft, ShieldCheck } from 'lucide-react';

export const administration: SidebarItem[] = [
    {
        label: 'Auth',
        icon: ShieldCheck,
        href: '/auth',
        children: [
            { label: 'Users', href: '/auth/users' },
            { label: 'Roles', href: '/auth/roles' },
            { label: 'Permissions', href: '/auth/permissions' },
        ],
    },
    { label: 'Media', icon: PanelsTopLeft, href: '/media' },
    { label: 'Activity Log', icon: Activity, href: '/activity-log' },
    { label: 'System Health', icon: HeartPulse, href: '/system-health' },
];
