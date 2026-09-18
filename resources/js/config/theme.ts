export const themeConfig = {
    brand: {
        eyebrow: 'Admin Panel',
        name: 'Aperture',
        mark: 'A',
        image: null as string | null,
    },
    login: {
        title: 'Welcome back',
        description: 'Log in to access the admin portal',
        secureLabel: 'Secure admin access',
    },
} as const;

export type ThemePreference = 'system' | 'light' | 'dark';
