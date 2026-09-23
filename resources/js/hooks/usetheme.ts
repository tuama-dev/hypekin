import { useState, useSyncExternalStore } from 'react';

function subscribeToSystemTheme(callback: () => void) {
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    mediaQuery.addEventListener('change', callback);
    return () => mediaQuery.removeEventListener('change', callback);
}

function getSystemTheme() {
    return window.matchMedia('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light';
}

export function useTheme() {
    const systemTheme = useSyncExternalStore(
        subscribeToSystemTheme,
        getSystemTheme,
        () => 'light',
    );
    const [themeOverride, setThemeOverride] = useState<'light' | 'dark' | null>(
        null,
    );
    const theme = themeOverride ?? systemTheme;

    function toggleTheme() {
        setThemeOverride(theme === 'light' ? 'dark' : 'light');
    }

    return { theme, toggleTheme };
}
