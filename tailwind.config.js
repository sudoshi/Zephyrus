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
                    primary: {
                        DEFAULT: '#2563EB', // Deep blue for Hospital
                        dark: '#3B82F6',
                        hover: {
                            DEFAULT: '#1D4ED8',
                            dark: '#2563EB'
                        }
                    },
                    purple: {
                        DEFAULT: '#7C3AED', // Purple for Transition
                        dark: '#8B5CF6',
                    },
                    orange: {
                        DEFAULT: '#F97316', // Orange for Home Setup
                        dark: '#FB923C',
                    },
                    success: {
                        DEFAULT: '#059669', // Green for Active Care
                        dark: '#10B981',
                    },
                    teal: {
                        DEFAULT: '#0D9488', // Teal for Monitoring
                        dark: '#14B8A6',
                    },
                    critical: {
                        DEFAULT: '#DC2626',
                        dark: '#EF4444',
                    },
                    warning: {
                        DEFAULT: '#D97706',
                        dark: '#F59E0B',
                    },
                    info: {
                        DEFAULT: '#0284C7',
                        dark: '#0EA5E9',
                    },
                    background: {
                        DEFAULT: '#F8FAFC', // Lighter background for better contrast
                        dark: '#0F172A',    // Darker background for dark mode
                        soft: '#F1F5F9',    // Softer tone for subtle backgrounds
                    },
                    surface: {
                        DEFAULT: '#FFFFFF', // Pure white for main surfaces
                        dark: '#1E293B',    // Rich dark blue for dark mode
                        secondary: '#F1F5F9', // Subtle gray for secondary surfaces
                        tertiary: '#F8FAFC', // Lightest surface for specific cases
                    },
                    text: {
                        primary: {
                            DEFAULT: '#1E293B', // Rich dark blue for primary text
                            dark: '#F8FAFC',    // Very light blue for dark mode
                        },
                        secondary: {
                            DEFAULT: '#475569', // Slate for secondary text
                            dark: '#CBD5E1',    // Light gray for dark mode
                        },
                    },
                    border: {
                        DEFAULT: '#E2E8F0', // Light gray for borders
                        dark: '#334155',    // Darker for better contrast in dark mode
                    },
                    hover: {
                        DEFAULT: '#F1F5F9', // Light hover effect
                        dark: '#334155',    // Dark mode hover
                    },
                    panel: {
                        DEFAULT: '#FFFFFF', // White panels
                        secondary: '#F8FAFC', // Subtle background for nested panels
                        dark: '#1E293B',    // Dark mode panels
                    },
                    accent: {
                        DEFAULT: '#3B82F6', // Accent color for highlights
                        dark: '#60A5FA',    // Lighter accent for dark mode
                    }
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
