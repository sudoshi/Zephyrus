import React, { createContext, useContext, useState, useEffect } from 'react';
import type { ReactNode, Dispatch, SetStateAction } from 'react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import TopNavigation from '@/Components/Navigation/TopNavigation';
import ChangePasswordModal from '@/Components/ChangePasswordModal';
import { useDashboard } from '@/Contexts/DashboardContext';
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

export default function AuthenticatedLayout({ header, children }: AuthenticatedLayoutProps) {
    const { currentWorkflow } = useDashboard();
    const { auth } = usePage<PageProps>().props;
    const mustChangePassword = auth?.user?.must_change_password;
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

    return (
        <DarkModeContext.Provider value={{ isDarkMode, setIsDarkMode }}>
            <div className="min-h-screen bg-gray-100 dark:bg-gray-900">
                {/* Force password change modal */}
                {mustChangePassword && <ChangePasswordModal />}

                {/* Navigation */}
                <TopNavigation isDarkMode={isDarkMode} setIsDarkMode={setIsDarkMode} />

                {/* Header */}
                {header && (
                    <header className="bg-white dark:bg-gray-800 shadow">
                        <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                            {header}
                        </div>
                    </header>
                )}

                {/* Main Content */}
                <main>{children}</main>
            </div>
        </DarkModeContext.Provider>
    );
}
