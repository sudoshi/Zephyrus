import React, { createContext, useContext, useState, useEffect } from 'react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import TopNavigation from '@/Components/Navigation/TopNavigation';
import { useDashboard } from '@/Contexts/DashboardContext';

// Create a context for dark mode
export const DarkModeContext = createContext({
    isDarkMode: false,
    setIsDarkMode: () => {}
});

// Custom hook to use dark mode
export const useDarkMode = () => useContext(DarkModeContext);

export default function AuthenticatedLayout({ header, children }) {
    const { currentWorkflow } = useDashboard();
    const [isDarkMode, setIsDarkMode] = useState(() => {
        const savedTheme = localStorage.getItem('darkMode');
        // If no preference is stored, use dark mode by default
        // or check system preferences
        return savedTheme === 'true' || (savedTheme === null && window.matchMedia('(prefers-color-scheme: dark)').matches) || savedTheme === null;
    });

    // Set localStorage when dark mode changes
    useEffect(() => {
        localStorage.setItem('darkMode', isDarkMode);
        if (isDarkMode) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    }, [isDarkMode]);

    return (
        <DarkModeContext.Provider value={{ isDarkMode, setIsDarkMode }}>
            <div className="min-h-screen bg-gray-100 dark:bg-gray-900">
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
