import React, { useEffect } from 'react';
import { Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { useDarkMode } from '@/hooks/useDarkMode';
import LoginRipple from '@/assets/Login-Ripple.svg';

export default function GuestLayout({ children }) {
    const [isDarkMode] = useDarkMode();


    return (
        <div className="flex min-h-screen flex-col items-center bg-healthcare-background dark:bg-healthcare-background-dark pt-[30px] transition-colors duration-300 relative">
            <div className="absolute inset-0 w-full h-full overflow-hidden pointer-events-none">
                <img src={LoginRipple} alt="" className="w-full h-full object-cover opacity-30 dark:opacity-20" />
            </div>
            <div className="relative">
                <Link href="/" className="block">
                    <ApplicationLogo 
                        variant="full"
                        className="h-[300px] w-auto text-healthcare-info dark:text-healthcare-info-dark transition-colors duration-300"
                    />
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-healthcare-surface/90 dark:bg-healthcare-surface-dark/90 backdrop-blur-sm px-6 py-4 shadow-md sm:max-w-lg sm:rounded-lg border border-healthcare-border dark:border-healthcare-border-dark transition-all duration-300 min-h-[400px] relative z-10">
                {children}
            </div>
        </div>
    );
}
