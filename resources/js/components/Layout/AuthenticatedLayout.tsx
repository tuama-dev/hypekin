import type { ReactNode } from "react";
import { useState } from "react";
import Navbar from "@/components/Layout/Navbar";
import Sidebar from "@/components/Layout/Sidebar";
import { appTheme } from "@/config/theme";
import CustomToaster from "@/components/ui/CustomToaster";

interface Props {
    children: ReactNode;
}

export default function AuthenticatedLayout({ children }: Props) {
    const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false);
    const [isMobileSidebarOpen, setIsMobileSidebarOpen] = useState(false);

    function toggleSidebar() {
        if (window.matchMedia("(max-width: 1023px)").matches) {
            setIsMobileSidebarOpen((current) => !current);
            return;
        }

        setIsSidebarCollapsed((current) => !current);
    }

    return (
        <div
            className="min-h-svh bg-(--dashboard-background) font-sans text-(--text)"
            style={
                {
                    "--accent-gradient": appTheme.accentGradient,
                } as React.CSSProperties
            }
        >
            <div className="flex min-h-svh">
                <Sidebar
                    isCollapsed={isSidebarCollapsed}
                    isOpen={isMobileSidebarOpen}
                    onClose={() => setIsMobileSidebarOpen(false)}
                />
                <div className="min-w-0 flex-1">
                    <Navbar
                        isSidebarCollapsed={isSidebarCollapsed}
                        isSidebarOpen={isMobileSidebarOpen}
                        onToggleSidebar={toggleSidebar}
                    />
                    {children}
                </div>
            </div>
            <CustomToaster />
        </div>
    );
}
