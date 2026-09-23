import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            flash: {
                error: string | null;
                success: string | null;
            };
            verification: {
                resend_available_at: string | null;
            } | null;
            [key: string]: unknown;
        };
    }
}
