import React from 'react';
import { useDarkMode } from '@/hooks/useDarkMode';
import { TopNavbar } from '@/Components/Navigation/TopNavbar';

const DashboardLayout = ({ children }) => {
    const [isDarkMode, setIsDarkMode] = useDarkMode();

    return (
        <div className="min-h-screen bg-healthcare-background dark:bg-healthcare-background-dark transition-colors duration-300">
            <TopNavbar isDarkMode={isDarkMode} setIsDarkMode={setIsDarkMode} />
            <main className="py-4 px-4 sm:px-6 max-w-full overflow-x-hidden transition-colors duration-300">
                {children}
            </main>
        </div>
    );
};

export default DashboardLayout;
