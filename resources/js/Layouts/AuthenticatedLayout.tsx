import React, { createContext, useContext, useState, useEffect } from 'react';
import type { ReactNode, Dispatch, SetStateAction } from 'react';
import TopNavigation from '@/Components/Navigation/TopNavigation';
import ChangePasswordModal from '@/Components/ChangePasswordModal';
import { CommandPalette } from '@/components/ui/CommandPalette';
import { Sidebar } from '@/components/layout/Sidebar';
import { MobileDrawer, MobileDrawerTrigger } from '@/components/layout/MobileDrawer';
import { useDashboard } from '@/Contexts/DashboardContext';
import { usePage } from '@inertiajs/react';
import { useUIStore } from '@/stores/uiStore';
import type { PageProps } from '@/types';

interface DarkModeContextType {
    isDarkMode: boolean;
    setIsDarkMode: Dispatch<SetStateAction<boolean>>;
}

interface AuthenticatedLayoutProps {
    header?: ReactNode;
    children: ReactNode;
}

// Create a context for dark mode
export const DarkModeContext = createContext<DarkModeContextType>({
    isDarkMode: false,
    setIsDarkMode: () => {}
});

// Custom hook to use dark mode
export const useDarkMode = (): DarkModeContextType => useContext(DarkModeContext);

/* Google Fonts for Acumenus Clinical Design System */
const GOOGLE_FONTS_URL =
    'https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@400;600;700&family=Source+Serif+4:wght@400;600;700&family=Source+Sans+3:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap';

export default function AuthenticatedLayout({ header, children }: AuthenticatedLayoutProps) {
    const { currentWorkflow } = useDashboard();
    const { auth } = usePage<PageProps>().props;
    const mustChangePassword = auth?.user?.must_change_password;
    const { sidebarOpen } = useUIStore();
    const [isDarkMode, setIsDarkMode] = useState<boolean>(() => {
        const savedTheme = localStorage.getItem('darkMode');
        // If no preference is stored, use dark mode by default
        // or check system preferences
        return savedTheme === 'true' || (savedTheme === null && window.matchMedia('(prefers-color-scheme: dark)').matches) || savedTheme === null;
    });

    // Set localStorage when dark mode changes
    useEffect(() => {
        localStorage.setItem('darkMode', String(isDarkMode));
        if (isDarkMode) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    }, [isDarkMode]);

    // Inject Google Fonts link if not already present
    useEffect(() => {
        const existingLink = document.querySelector(`link[href="${GOOGLE_FONTS_URL}"]`);
        if (existingLink) return;

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = GOOGLE_FONTS_URL;
        document.head.appendChild(link);
    }, []);

    const sidebarWidth = sidebarOpen ? 'var(--sidebar-width)' : 'var(--sidebar-width-collapsed)';

    return (
        <DarkModeContext.Provider value={{ isDarkMode, setIsDarkMode }}>
            {/* Skip to content link for accessibility */}
            <a href="#main-content" className="skip-to-content">
                Skip to content
            </a>

            <div
                style={{
                    minHeight: '100vh',
                    background: 'var(--surface-base)',
                    color: 'var(--text-primary)',
                    fontFamily: 'var(--font-body)',
                }}
            >
                {/* Force password change modal */}
                {mustChangePassword && <ChangePasswordModal />}

                {/* Desktop Sidebar — hidden below 1024px */}
                <div className="desktop-sidebar">
                    <Sidebar />
                    <style>{`
                        @media (max-width: 1023px) {
                            .desktop-sidebar { display: none !important; }
                        }
                    `}</style>
                </div>

                {/* Mobile Drawer — visible below 1024px */}
                <MobileDrawer />

                {/* Main area offset by sidebar */}
                <div
                    className="layout-main"
                    style={{
                        marginLeft: sidebarWidth,
                        transition: `margin-left var(--duration-slow) var(--ease-out)`,
                        minHeight: '100vh',
                        display: 'flex',
                        flexDirection: 'column',
                    }}
                >
                    {/* Top bar with mobile trigger and existing navigation */}
                    <div
                        style={{
                            position: 'sticky',
                            top: 0,
                            zIndex: 'var(--z-topbar)' as unknown as number,
                            background: 'var(--surface-raised)',
                            borderBottom: '1px solid var(--border-subtle)',
                        }}
                    >
                        <div
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 'var(--space-2)',
                            }}
                        >
                            {/* Mobile hamburger trigger */}
                            <MobileDrawerTrigger />

                            {/* Existing TopNavigation */}
                            <div style={{ flex: 1 }}>
                                <TopNavigation isDarkMode={isDarkMode} setIsDarkMode={setIsDarkMode} />
                            </div>
                        </div>
                    </div>

                    {/* Header */}
                    {header && (
                        <header
                            style={{
                                background: 'var(--surface-raised)',
                                borderBottom: '1px solid var(--border-subtle)',
                                boxShadow: 'var(--shadow-xs)',
                            }}
                        >
                            <div
                                style={{
                                    maxWidth: 'var(--content-max-width)',
                                    margin: '0 auto',
                                    padding: `var(--space-4) var(--content-padding)`,
                                }}
                            >
                                {header}
                            </div>
                        </header>
                    )}

                    {/* Main Content */}
                    <main
                        id="main-content"
                        style={{
                            flex: 1,
                            maxWidth: 'var(--content-max-width)',
                            width: '100%',
                            margin: '0 auto',
                        }}
                    >
                        {children}
                    </main>
                </div>

                {/* Responsive override: remove margin on mobile */}
                <style>{`
                    @media (max-width: 1023px) {
                        .layout-main { margin-left: 0 !important; }
                    }
                `}</style>

                {/* Command Palette (Cmd+K / Ctrl+K) */}
                <CommandPalette />
            </div>
        </DarkModeContext.Provider>
    );
}
