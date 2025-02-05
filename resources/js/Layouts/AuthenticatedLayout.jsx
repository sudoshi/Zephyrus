import React from 'react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import TopNavigation from '@/Components/Navigation/TopNavigation';
import { useDashboard } from '@/Contexts/DashboardContext';
import { useState } from 'react';

export default function AuthenticatedLayout({ header, children }) {
    const { currentWorkflow } = useDashboard();
    const [isDarkMode, setIsDarkMode] = useState(false);

    return (
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
    );
}
