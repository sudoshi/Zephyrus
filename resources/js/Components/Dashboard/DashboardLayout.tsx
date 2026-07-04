// resources/js/Components/Dashboard/DashboardLayout.tsx
//
// The ONE app shell (Zephyrus 2.0 P4b). Every authenticated page renders
// through this layout — directly or via the thin chrome wrappers
// (RTDCPageLayout, TransportLayout, AnalyticsLayout). It owns:
//   - the TopNavbar (altitude nav, RoleSwitcher, CommandPalette),
//   - the shell-level DarkModeContext provider,
//   - the forced-password-change gate: ChangePasswordModal renders on every
//     page while must_change_password is set. It is never dismissable and the
//     server-side login redirect remains the initial gate
//     (.claude/rules/auth-system.md — this mount is an addition, not a move).
import React from 'react';
import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { useDarkMode } from '@/hooks/useDarkMode';
import { DarkModeContext } from '@/Contexts/DarkModeContext';
import { TopNavbar } from '@/Components/Navigation/TopNavbar';
import ChangePasswordModal from '@/Components/ChangePasswordModal';
import type { PageProps } from '@/types';

const DashboardLayout = ({ children }: { children: ReactNode }) => {
    const [isDarkMode, setIsDarkMode] = useDarkMode();
    const { auth } = usePage<PageProps>().props;
    const mustChangePassword = Boolean(auth?.user?.must_change_password);

    return (
        <DarkModeContext.Provider value={{ isDarkMode, setIsDarkMode }}>
            {/* Skip to content link for accessibility */}
            <a href="#main-content" className="skip-to-content">
                Skip to content
            </a>
            <div className="min-h-screen bg-healthcare-background dark:bg-healthcare-background-dark transition-colors duration-300">
                {/* Force password change modal — preserved per auth-system rules */}
                {mustChangePassword && <ChangePasswordModal />}
                <TopNavbar isDarkMode={isDarkMode} setIsDarkMode={setIsDarkMode} />
                {/* Page shell: max-width centering only. The page gutter (p-4) is
                    owned solely by PageContentLayout so panels never get a double
                    gutter. Content is capped at --content-max-width (1600px). */}
                <main
                    id="main-content"
                    className="max-w-[var(--content-max-width)] mx-auto overflow-x-hidden transition-colors duration-300"
                >
                    {children}
                </main>
            </div>
        </DarkModeContext.Provider>
    );
};

export default DashboardLayout;
