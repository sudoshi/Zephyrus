import React, { createContext, useContext, useState, useEffect } from 'react';
import type { ReactNode, Dispatch, SetStateAction } from 'react';
import { TopNavbar } from '@/Components/Navigation/TopNavbar';
import ChangePasswordModal from '@/Components/ChangePasswordModal';
import { usePage } from '@inertiajs/react';
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
    const { auth } = usePage<PageProps>().props;
    const mustChangePassword = auth?.user?.must_change_password;
    const [isDarkMode, setIsDarkMode] = useState<boolean>(() => {
        const savedTheme = localStorage.getItem('darkMode');
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
                    display: 'flex',
                    flexDirection: 'column',
                }}
            >
                {/* Force password change modal — preserved per auth-system rules */}
                {mustChangePassword && <ChangePasswordModal />}

                {/* Single consolidated top navbar (mounts CommandPalette internally) */}
                <TopNavbar isDarkMode={isDarkMode} setIsDarkMode={setIsDarkMode} />

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
        </DarkModeContext.Provider>
    );
}
