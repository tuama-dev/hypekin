import { useEffect, useState } from 'react';
import { Moon, Sun } from 'lucide-react';
import type { ThemePreference } from '@/config/theme';

const storageKey = 'appearance';

export function ThemeToggle() {
    const [preference, setPreference] = useState<ThemePreference>('system');

    useEffect(() => {
        const stored = window.localStorage.getItem(storageKey) as ThemePreference | null;
        const next = stored === 'light' || stored === 'dark' ? stored : 'system';
        setPreference(next);
        applyTheme(next);
    }, []);

    function toggleTheme() {
        const next = preference === 'dark' ? 'light' : 'dark';
        setPreference(next);
        window.localStorage.setItem(storageKey, next);
        applyTheme(next);
    }

    return (
        <button
            type="button"
            onClick={toggleTheme}
            className="inline-flex h-10 items-center gap-2 rounded-xl border bg-[var(--color-panel)] px-3.5 text-xs font-bold text-[var(--color-muted)] shadow-sm transition hover:border-[var(--color-accent-start)] hover:text-[var(--color-ink)] focus:outline-none focus:ring-4 focus:ring-[color:var(--color-accent-start)]/15"
            aria-label={`Switch to ${preference === 'dark' ? 'light' : 'dark'} theme`}
        >
            {preference === 'dark' ? <Sun size={15} strokeWidth={2.25} aria-hidden="true" /> : <Moon size={15} strokeWidth={2.25} aria-hidden="true" />}
            {preference === 'dark' ? 'Light' : 'Dark'}
        </button>
    );
}

function applyTheme(preference: ThemePreference) {
    document.documentElement.dataset.theme = preference;
}