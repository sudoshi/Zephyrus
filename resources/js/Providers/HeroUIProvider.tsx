import React from 'react';
import type { ReactNode } from 'react';
import { HeroUIProvider } from '@heroui/react';
import { DashboardProvider } from '@/Contexts/DashboardContext';
import { ModeProvider } from '@/Contexts/ModeContext';
import { usePage } from '@inertiajs/react';

interface ProvidersProps {
    children: ReactNode;
}

export function Providers({ children }: ProvidersProps) {
    const { url } = usePage();

    return (
        <HeroUIProvider>
            <ModeProvider>
                <DashboardProvider currentUrl={url}>
                    <div className="min-h-screen bg-healthcare-background dark:bg-healthcare-background-dark">
                        {children}
                    </div>
                </DashboardProvider>
            </ModeProvider>
        </HeroUIProvider>
    );
}
