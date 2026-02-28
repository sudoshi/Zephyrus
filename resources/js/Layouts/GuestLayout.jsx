import React, { useEffect } from 'react';
import { Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { useDarkMode } from '@/hooks/useDarkMode';
import LoginRipple from '@/assets/Login-Ripple.svg';

export default function GuestLayout({ children }) {
    const [isDarkMode] = useDarkMode();

    return (
        <div className="min-h-screen bg-gradient-to-br from-healthcare-background via-healthcare-background/95 to-healthcare-background dark:from-healthcare-background-dark dark:via-healthcare-background-dark/95 dark:to-healthcare-background-dark transition-all duration-500 relative overflow-hidden">
            {/* Enhanced Background with Ripple */}
            <div className="absolute inset-0 w-full h-full">
                <img 
                    src={LoginRipple} 
                    alt="" 
                    className="w-full h-full object-cover opacity-40 dark:opacity-25 transition-opacity duration-500" 
                />
                {/* Subtle overlay gradient */}
                <div className="absolute inset-0 bg-gradient-to-br from-primary-50/20 via-transparent to-primary-100/20 dark:from-primary-900/10 dark:via-transparent dark:to-primary-800/10" />
            </div>
            
            {/* Floating particles effect */}
            <div className="absolute inset-0 overflow-hidden pointer-events-none">
                <div className="absolute top-1/4 left-1/4 w-2 h-2 bg-primary-400/30 rounded-full animate-pulse" />
                <div className="absolute top-3/4 right-1/4 w-1 h-1 bg-primary-500/40 rounded-full animate-pulse" style={{ animationDelay: '1s' }} />
                <div className="absolute bottom-1/3 left-1/3 w-1.5 h-1.5 bg-primary-300/20 rounded-full animate-pulse" style={{ animationDelay: '2s' }} />
            </div>

            <div className="relative z-10 flex min-h-screen flex-col items-center justify-center px-4 py-12">
                {/* Elegant Logo */}
                <div className="mb-8">
                    <Link href="/" className="block group">
                        <ApplicationLogo 
                            variant="full"
                            className="h-24 w-auto text-healthcare-info dark:text-healthcare-info-dark transition-all duration-300 group-hover:scale-105 drop-shadow-sm"
                        />
                    </Link>
                </div>

                {/* Main Content Container */}
                <div className="w-full max-w-md relative">
                    {children}
                </div>
            </div>
        </div>
    );
}
