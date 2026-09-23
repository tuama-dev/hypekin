export type ThemePreference = "system" | "light" | "dark";

export const themeConfig = {
    brand: {
        eyebrow: "HypeKin Account Access",
        name: "HypeKin",
        mark: "H",
        image: null as string | null,
    },
    login: {
        title: "Welcome back",
        description: "Log in to access your HypeKin account",
        secureLabel: "HypeKin Secure access",
    },
    register: {
        title: "Create your account",
        description: "Join HypeKin and get started now",
    },
} as const;

export const appTheme = {
    brandName: "HypeKin",
    logoSrc: "",
    logoFallback: "H",
    welcomeTitle: "Welcome back",
    welcomeMessage: "Login to access the admin portal",
    accentGradient: "linear-gradient(145deg, #6478d7 0%, #7a2bb6 100%)",
    fontFamily: "Nunito, sans-serif",
    user: {
        initials: "JD",
        displayName: "Developer",
        menuItems: [
            { label: "Profile", href: "/profile", icon: "user" },
            { label: "Logout", href: "/app/logout", icon: "logout" },
        ],
    },
} as const;
