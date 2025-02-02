import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
const { heroui } = require("@heroui/react");

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
        './node_modules/@heroui/theme/dist/**/*.{js,ts,jsx,tsx}',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                healthcare: {
                    critical: {
                        DEFAULT: '#DC2626', // red-600
                        dark: '#EF4444',     // red-500
                    },
                    warning: {
                        DEFAULT: '#D97706', // amber-600
                        dark: '#F59E0B',     // amber-500
                    },
                    success: {
                        DEFAULT: '#059669', // emerald-600
                        dark: '#10B981',     // emerald-500
                    },
                    info: {
                        DEFAULT: '#2563EB', // blue-600
                        dark: '#3B82F6',     // blue-500
                    },
                    background: {
                        DEFAULT: '#F9FAFB', // gray-50
                        dark: '#111827',     // gray-900
                    },
                    surface: {
                        DEFAULT: '#FFFFFF', // white
                        dark: '#1F2937',     // gray-800
                    },
                    text: {
                        primary: {
                            DEFAULT: '#111827', // gray-900
                            dark: '#F9FAFB',     // gray-50
                        },
                        secondary: {
                            DEFAULT: '#4B5563', // gray-600
                            dark: '#E5E7EB',     // gray-200 (improved contrast)
                        },
                    },
                    border: {
                        DEFAULT: '#E5E7EB', // gray-200
                        dark: '#4B5563',     // gray-600 (improved contrast)
                    },
                },
            },
            fontSize: {
                // Larger default sizes for better readability
                xs: ['0.75rem', '1rem'],      // 12px
                sm: ['0.875rem', '1.25rem'],  // 14px
                base: ['1rem', '1.5rem'],     // 16px
                lg: ['1.125rem', '1.75rem'],  // 18px
                xl: ['1.25rem', '1.75rem'],   // 20px
                '2xl': ['1.5rem', '2rem'],    // 24px
                '3xl': ['1.875rem', '2.25rem'], // 30px
                '4xl': ['2.25rem', '2.5rem'], // 36px
                '5xl': ['3rem', '1'],         // 48px
                '6xl': ['3.75rem', '1'],      // 60px
            },
            spacing: {
                // Existing spacing
                touch: '44px',
                // Additional spacing scales
                18: '4.5rem', // 72px
                22: '5.5rem', // 88px
                26: '6.5rem', // 104px
                30: '7.5rem', // 120px
            },
            boxShadow: {
                'blue-light': '0 4px 12px rgba(37, 99, 235, 0.1)',
                'blue-dark': '0 4px 12px rgba(59, 130, 246, 0.1)',
                // Shadow system for depth
                xs: '0 0 0 1px rgba(0, 0, 0, 0.05)',
                sm: '0 1px 2px 0 rgba(0, 0, 0, 0.05)',
                DEFAULT: '0 1px 3px 0 rgba(0, 0, 0, 0.1)',
                md: '0 4px 6px -1px rgba(0, 0, 0, 0.1)',
                lg: '0 10px 15px -3px rgba(0, 0, 0, 0.1)',
                xl: '0 20px 25px -5px rgba(0, 0, 0, 0.1)',
                '2xl': '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
                inner: 'inset 0 2px 4px 0 rgba(0,0,0,0.06)',
            },
        },
    },

    darkMode: "class",
    plugins: [
    forms({
        strategy: 'class',
    }),
    heroui(),
],
};
