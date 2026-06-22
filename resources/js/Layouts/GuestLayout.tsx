import React from 'react';
import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { useDarkMode } from '@/hooks/useDarkMode';

interface ElegantMarkProps {
    className?: string;
}

/* Elegant abstract mark — concentric arcs evoking a rising pulse / horizon */
const ElegantMark = ({ className = '' }: ElegantMarkProps) => (
    <svg
        viewBox="0 0 80 80"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        className={className}
    >
        <defs>
            <linearGradient id="mark-grad" x1="0" y1="0" x2="80" y2="80" gradientUnits="userSpaceOnUse">
                <stop stopColor="#6366f1" />
                <stop offset="0.5" stopColor="#3b82f6" />
                <stop offset="1" stopColor="#06b6d4" />
            </linearGradient>
        </defs>
        {/* Outer arc */}
        <path
            d="M12 58 A30 30 0 0 1 68 58"
            stroke="url(#mark-grad)"
            strokeWidth="2.5"
            strokeLinecap="round"
            opacity="0.35"
        />
        {/* Middle arc */}
        <path
            d="M22 54 A20 20 0 0 1 58 54"
            stroke="url(#mark-grad)"
            strokeWidth="2.5"
            strokeLinecap="round"
            opacity="0.6"
        />
        {/* Inner arc */}
        <path
            d="M30 50 A12 12 0 0 1 50 50"
            stroke="url(#mark-grad)"
            strokeWidth="3"
            strokeLinecap="round"
        />
        {/* Centre dot */}
        <circle cx="40" cy="38" r="3.5" fill="url(#mark-grad)" />
    </svg>
);

interface GuestLayoutProps {
    children: ReactNode;
}

export default function GuestLayout({ children }: GuestLayoutProps) {
    const [isDarkMode] = useDarkMode();

    return (
        <div className="guest-page min-h-screen bg-[#fafbfe] dark:bg-[#0b1120] transition-colors duration-500 relative overflow-hidden">
            {/* Mesh gradient background */}
            <div className="absolute inset-0">
                <div className="absolute -top-[40%] -left-[20%] w-[70%] h-[70%] rounded-full bg-indigo-200/40 dark:bg-indigo-900/20 blur-[120px]" />
                <div className="absolute -bottom-[30%] -right-[10%] w-[60%] h-[60%] rounded-full bg-cyan-200/30 dark:bg-cyan-900/15 blur-[120px]" />
                <div className="absolute top-[20%] right-[15%] w-[40%] h-[40%] rounded-full bg-blue-100/30 dark:bg-blue-900/10 blur-[100px]" />
            </div>

            {/* Subtle grid overlay */}
            <div
                className="absolute inset-0 opacity-[0.03] dark:opacity-[0.04]"
                style={{
                    backgroundImage:
                        'linear-gradient(rgba(99,102,241,.4) 1px, transparent 1px), linear-gradient(90deg, rgba(99,102,241,.4) 1px, transparent 1px)',
                    backgroundSize: '64px 64px',
                }}
            />

            <div className="relative z-10 flex min-h-screen flex-col items-center justify-center px-4 py-8">
                {/* Elegant mark + wordmark */}
                <Link href="/" className="mb-6 flex flex-col items-center gap-3 group">
                    <ElegantMark className="h-12 w-12 transition-transform duration-300 group-hover:scale-105" />
                    <span className="text-[1.35rem] font-extralight tracking-[.35em] uppercase text-slate-700 dark:text-slate-300 select-none">
                        Zephyrus
                    </span>
                </Link>

                {/* Main Content Container */}
                <div className="w-full max-w-md relative">
                    {children}
                </div>

                {/* Minimal footer */}
                <p className="mt-8 text-[0.7rem] tracking-widest uppercase text-slate-400 dark:text-slate-600 select-none">
                    Healthcare Operations Platform
                </p>
            </div>
        </div>
    );
}
