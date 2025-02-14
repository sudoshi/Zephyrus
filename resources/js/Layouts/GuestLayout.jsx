import React, { useEffect } from 'react';
import { Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { useDarkMode } from '@/hooks/useDarkMode';

export default function GuestLayout({ children }) {
    const [isDarkMode] = useDarkMode();

    // Apply dark mode class based on isDarkMode
    useEffect(() => {
        if (isDarkMode) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    }, [isDarkMode]);

    return (
        <div className="flex min-h-screen flex-col items-center bg-healthcare-background dark:bg-healthcare-background-dark pt-[30px] transition-colors duration-300">
            <div className="relative">
                <Link href="/" className="block">
                    <ApplicationLogo 
                        variant="full"
                        className="h-[300px] w-auto text-healthcare-info dark:text-healthcare-info-dark transition-colors duration-300"
                    />
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-healthcare-surface dark:bg-healthcare-surface-dark px-6 py-4 shadow-md sm:max-w-lg sm:rounded-lg border border-healthcare-border dark:border-healthcare-border-dark transition-all duration-300 min-h-[400px]">
                {children}
            </div>
        </div>
    );
}
