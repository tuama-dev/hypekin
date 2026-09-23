import type { ReactElement, SVGProps } from "react";
import SocialAuthController from "@/actions/App/Http/Controllers/Application/Auth/SocialAuthController";

type SocialProvider = "facebook" | "x" | "linkedin-openid" | "google";

type ProviderConfig = {
    provider: SocialProvider;
    label: string;
    icon: (props: SVGProps<SVGSVGElement>) => ReactElement;
};

function FacebookIcon(props: SVGProps<SVGSVGElement>): ReactElement {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
            {...props}
        >
            <path d="M24 12.073C24 5.446 18.627.073 12 .073S0 5.446 0 12.073c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
        </svg>
    );
}

function XIcon(props: SVGProps<SVGSVGElement>): ReactElement {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
            {...props}
        >
            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231L18.244 2.25zm-1.161 17.52h1.833L7.084 4.126H5.117l11.966 15.644z" />
        </svg>
    );
}

function LinkedInIcon(props: SVGProps<SVGSVGElement>): ReactElement {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
            {...props}
        >
            <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.064 2.064 0 1 1 0-4.128 2.064 2.064 0 0 1 0 4.128zM7.119 20.452H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.225 0z" />
        </svg>
    );
}

function GoogleIcon(props: SVGProps<SVGSVGElement>): ReactElement {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true" {...props}>
            <path
                fill="#4285F4"
                d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"
            />
            <path
                fill="#34A853"
                d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z"
            />
            <path
                fill="#FBBC05"
                d="M5.27 14.29A8.13 8.13 0 0 1 4.89 12c0-.8.14-1.57.38-2.29V6.62H1.29C.47 8.24 0 10.06 0 12s.47 3.76 1.29 5.38l3.98-3.09z"
            />
            <path
                fill="#EA4335"
                d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"
            />
        </svg>
    );
}

const providers: ProviderConfig[] = [
    { provider: "google", label: "Google", icon: GoogleIcon },
    { provider: "facebook", label: "Facebook", icon: FacebookIcon },
    { provider: "x", label: "X", icon: XIcon },
    { provider: "linkedin-openid", label: "LinkedIn", icon: LinkedInIcon },
];

export function SocialButtons() {
    return (
        <div className="grid gap-5">
            <div className="grid grid-cols-2 gap-3">
                {providers.map(({ provider, label, icon: Icon }) => (
                    <a
                        key={provider}
                        href={SocialAuthController.redirect(provider).url}
                        className="flex h-11 w-full items-center justify-center gap-2 rounded-xl border bg-[var(--color-panel)] px-4 text-sm font-bold text-[var(--color-ink)] transition hover:-translate-y-0.5 hover:bg-[var(--color-panel-muted)] focus:outline-none focus:ring-4 focus:ring-[color:var(--color-accent-start)]/15"
                    >
                        <Icon className="h-[18px] w-[18px]" />
                        {label}
                    </a>
                ))}
            </div>
        </div>
    );
}
