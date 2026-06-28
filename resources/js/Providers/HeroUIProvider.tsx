import React from 'react';
import type { ReactNode } from 'react';
import { HeroUIProvider } from '@heroui/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { DashboardProvider } from '@/Contexts/DashboardContext';
import { ModeProvider } from '@/Contexts/ModeContext';
import { ToastProvider } from '@/components/ui/Toast';
import { EddyDock } from '@/Components/Eddy/EddyDock';
import { queryClient } from '@/lib/queryClient';
import { usePage } from '@inertiajs/react';

interface ProvidersProps {
    children: ReactNode;
}

export function Providers({ children }: ProvidersProps) {
    const { url } = usePage();

    return (
        <QueryClientProvider client={queryClient}>
            <HeroUIProvider>
                <ModeProvider>
                    <DashboardProvider currentUrl={url}>
                        <div className="min-h-screen bg-healthcare-background dark:bg-healthcare-background-dark">
                            {children}
                        </div>
                        <ToastProvider />
                        <EddyDock />
                    </DashboardProvider>
                </ModeProvider>
            </HeroUIProvider>
            <ReactQueryDevtools initialIsOpen={false} />
        </QueryClientProvider>
    );
}
