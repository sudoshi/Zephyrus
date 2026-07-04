import React from 'react';
import type { ReactNode } from 'react';
import { HeroUIProvider } from '@heroui/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { ModeProvider } from '@/Contexts/ModeContext';
import { ToastProvider } from '@/components/ui/Toast';
import { EddyDock } from '@/Components/Eddy/EddyDock';
import { queryClient } from '@/lib/queryClient';

interface ProvidersProps {
    children: ReactNode;
}

export function Providers({ children }: ProvidersProps) {
    return (
        <QueryClientProvider client={queryClient}>
            <HeroUIProvider>
                <ModeProvider>
                    <div className="min-h-screen bg-healthcare-background dark:bg-healthcare-background-dark">
                        {children}
                    </div>
                    <ToastProvider />
                    <EddyDock />
                </ModeProvider>
            </HeroUIProvider>
            <ReactQueryDevtools initialIsOpen={false} />
        </QueryClientProvider>
    );
}
