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
import React, { useEffect } from 'react';
import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { useDarkMode } from '@/hooks/useDarkMode';
import { DarkModeContext } from '@/Contexts/DarkModeContext';
import { TopNavbar } from '@/Components/Navigation/TopNavbar';
import { WallClock } from '@/Components/cockpit/WallClock';
import ChangePasswordModal from '@/Components/ChangePasswordModal';
import type { PageProps } from '@/types';

interface DashboardLayoutProps {
    children: ReactNode;
    /** Skip the 1600px content cap — full-bleed surfaces only (4D navigator). */
    fullBleed?: boolean;
    /**
     * P8 WS-5 wall preset: strip the interactive chrome (TopNavbar/user menu) down
     * to a minimal wall strip, drop the content cap, and dark-lock the surface (a
     * bright wall in a dark unit is hostile). The RBAC session and the forced
     * ChangePasswordModal are preserved — a wall is authenticated, not a kiosk.
     * A soft `?theme=light` escape (D6, unadvertised) opts an atypical bright-NOC
     * deployment out of the dark lock.
     */
    wall?: boolean;
}

function themeParamIsLight(): boolean {
    if (typeof window === 'undefined') return false;
    return new URLSearchParams(window.location.search).get('theme') === 'light';
}

const DashboardLayout = ({ children, fullBleed = false, wall = false }: DashboardLayoutProps) => {
    const [isDarkMode, setIsDarkMode] = useDarkMode();
    const { auth } = usePage<PageProps>().props;
    const mustChangePassword = Boolean(auth?.user?.must_change_password);

    // Wall preset: authoritatively lock the theme (dark unless the soft
    // ?theme=light escape) and apply the wall type-scale to the ROOT (so the
    // rem-based cockpit text actually enlarges for across-the-room reading). We
    // drive the theme through the persisted setter but SNAPSHOT the prior
    // preference and restore it on unmount, so a transient wall visit never
    // rewrites the operator's light/dark choice.
    useEffect(() => {
        if (!wall) return;
        const root = document.documentElement;
        const priorPref =
            typeof localStorage !== 'undefined' ? localStorage.getItem('darkMode') : null;

        root.classList.add('cockpit-wall');
        setIsDarkMode(!themeParamIsLight()); // dark-lock, or force light on the escape

        return () => {
            root.classList.remove('cockpit-wall');
            if (typeof localStorage !== 'undefined') {
                if (priorPref === null) localStorage.removeItem('darkMode');
                else localStorage.setItem('darkMode', priorPref);
            }
            // Re-apply the restored preference (default dark, matching useDarkMode).
            setIsDarkMode(priorPref === null ? true : priorPref !== 'false');
        };
    }, [wall, setIsDarkMode]);

    return (
        <DarkModeContext.Provider value={{ isDarkMode, setIsDarkMode }}>
            {/* Skip to content link for accessibility */}
            <a href="#main-content" className="skip-to-content">
                Skip to content
            </a>
            <div className="min-h-screen bg-healthcare-background dark:bg-healthcare-background-dark transition-colors duration-300">
                {/* Force password change modal — preserved per auth-system rules
                    (a wall is authenticated; the gate still applies). */}
                {mustChangePassword && <ChangePasswordModal />}
                {wall ? (
                    // Wall chrome: no nav mega-menu, no user menu — just the facility
                    // identity and a 1 Hz clock so an on-wall glance stays oriented.
                    <header className="flex items-center justify-between border-b border-healthcare-border dark:border-healthcare-border-dark px-4 py-2">
                        <span className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            Zephyrus · Operations Cockpit
                        </span>
                        <WallClock />
                    </header>
                ) : (
                    <TopNavbar isDarkMode={isDarkMode} setIsDarkMode={setIsDarkMode} />
                )}
                {/* Page shell: max-width centering only. The page gutter (p-4) is
                    owned solely by PageContentLayout so panels never get a double
                    gutter. Content is capped at --content-max-width (1600px); wall +
                    fullBleed drop the cap. */}
                <main
                    id="main-content"
                    className={
                        fullBleed || wall
                            ? 'transition-colors duration-300'
                            : 'max-w-[var(--content-max-width)] mx-auto overflow-x-hidden transition-colors duration-300'
                    }
                >
                    {children}
                </main>
            </div>
        </DarkModeContext.Provider>
    );
};

export default DashboardLayout;
