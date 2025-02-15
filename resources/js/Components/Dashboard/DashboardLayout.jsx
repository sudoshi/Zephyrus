import React, { useEffect } from 'react';
import { useDarkMode } from '@/hooks/useDarkMode';
import TopNavigation from '@/Components/Navigation/TopNavigation';

const DashboardLayout = ({ children }) => {
    const [isDarkMode, setIsDarkMode] = useDarkMode();


    return (
        <div className="min-h-screen bg-healthcare-background dark:bg-healthcare-background-dark transition-colors duration-300">
            <TopNavigation isDarkMode={isDarkMode} setIsDarkMode={setIsDarkMode} />
            <main className="py-6 px-6 max-w-full overflow-x-hidden transition-colors duration-300">
                {children}
            </main>
        </div>
    );
};

export default DashboardLayout;
