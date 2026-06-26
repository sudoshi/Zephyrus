import React from 'react';
import { useDarkMode } from '@/hooks/useDarkMode';
import { TopNavbar } from '@/Components/Navigation/TopNavbar';

const DashboardLayout = ({ children }) => {
    const [isDarkMode, setIsDarkMode] = useDarkMode();

    return (
        <div className="min-h-screen bg-healthcare-background dark:bg-healthcare-background-dark transition-colors duration-300">
            <TopNavbar isDarkMode={isDarkMode} setIsDarkMode={setIsDarkMode} />
            {/* Page shell: max-width centering only. The page gutter (p-4) is
                owned solely by PageContentLayout so panels never get a double
                gutter. Content is capped at --content-max-width (1600px). */}
            <main className="max-w-[var(--content-max-width)] mx-auto overflow-x-hidden transition-colors duration-300">
                {children}
            </main>
        </div>
    );
};

export default DashboardLayout;
