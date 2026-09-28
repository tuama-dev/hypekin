import type { ReactElement, SVGProps } from "react";

export type PlatformValue = "linkedin" | "facebook" | "instagram" | "tiktok";

export function LinkedInIcon(props: SVGProps<SVGSVGElement>): ReactElement {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
            {...props}
        >
            <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.225 0z" />
        </svg>
    );
}

export function FacebookIcon(props: SVGProps<SVGSVGElement>): ReactElement {
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

export function InstagramIcon(props: SVGProps<SVGSVGElement>): ReactElement {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
            {...props}
        >
            <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z" />
        </svg>
    );
}

export function TikTokIcon(props: SVGProps<SVGSVGElement>): ReactElement {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
            {...props}
        >
            <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.51 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.88c.28 0 .55.05.8.13V9.05a6.34 6.34 0 0 0-.8-.05 6.34 6.34 0 1 0 6.34 6.34V9.51A8.16 8.16 0 0 0 19.59 6.69z" />
        </svg>
    );
}

export interface PlatformBrand {
    label: string;
    subtitle: string;
    icon: (props: SVGProps<SVGSVGElement>) => ReactElement;
    buttonClass: string;
    chipClass: string;
    borderClass: string;
    textClass: string;
    ringClass: string;
    solidClass: string;
}

export const platformBrands: Record<PlatformValue, PlatformBrand> = {
    facebook: {
        label: "Facebook",
        subtitle: "Connect the Facebook pages you manage.",
        icon: FacebookIcon,
        buttonClass: "bg-[#1877F2]",
        chipClass: "bg-[#1877F2]/10 text-[#1877F2]",
        borderClass: "border-[#1877F2]",
        textClass: "text-[#1877F2]",
        ringClass: "ring-[#1877F2]",
        solidClass: "bg-[#1877F2]",
    },
    instagram: {
        label: "Instagram",
        subtitle:
            "Connect the Instagram business accounts linked to your pages.",
        icon: InstagramIcon,
        buttonClass:
            "bg-linear-to-br from-[#F58529] via-[#DD2A7B] to-[#8134AF]",
        chipClass: "bg-[#DD2A7B]/10 text-[#DD2A7B]",
        borderClass: "border-[#DD2A7B]",
        textClass: "text-[#DD2A7B]",
        ringClass: "ring-[#DD2A7B]",
        solidClass: "bg-[#DD2A7B]",
    },
    linkedin: {
        label: "LinkedIn",
        subtitle: "Connect a profile to post text and image updates.",
        icon: LinkedInIcon,
        buttonClass: "bg-[#0A66C2]",
        chipClass: "bg-[#0A66C2]/10 text-[#0A66C2]",
        borderClass: "border-[#0A66C2]",
        textClass: "text-[#0A66C2]",
        ringClass: "ring-[#0A66C2]",
        solidClass: "bg-[#0A66C2]",
    },
    tiktok: {
        label: "TikTok",
        subtitle: "Connect a creator account to publish photos and videos.",
        icon: TikTokIcon,
        buttonClass: "bg-[#010101] shadow-[0_0_0_1px_rgba(37,244,238,0.3)]",
        chipClass: "bg-[#010101]/10 text-[#010101]",
        borderClass: "border-[#010101]",
        textClass: "text-[#010101]",
        ringClass: "ring-[#010101]",
        solidClass: "bg-[#010101]",
    },
};
