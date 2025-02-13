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
                        DEFAULT: '#E53E3E', // Softer red for better readability
                        dark: '#FC8181',    // Lighter red for dark mode
                    },
                    warning: {
                        DEFAULT: '#D69E2E', // Muted amber for less visual stress
                        dark: '#F6AD55',    // Lighter amber for dark mode
                    },
                    success: {
                        DEFAULT: '#38A169', // Muted green for professionalism
                        dark: '#68D391',    // Lighter green for dark mode
                    },
                    info: {
                        DEFAULT: '#4299E1', // Calming blue for trust
                        dark: '#63B3ED',    // Lighter blue for dark mode
                    },
                    background: {
                        DEFAULT: '#E2E8F0', // Deeper blue-gray background
                        dark: '#1A202C',    // Deep blue-gray for dark mode
                        soft: '#EDF2F7',    // Softer tone for subtle backgrounds
                    },
                    surface: {
                        DEFAULT: '#F1F5F9', // Softer main surface
                        dark: '#2D3748',    // Softer dark background
                        secondary: '#E2E8F0', // Deeper tone for secondary surfaces
                        tertiary: '#F8FAFC', // Lightest surface for specific cases
                    },
                    text: {
                        primary: {
                            DEFAULT: '#2D3748', // Softer than pure black
                            dark: '#F7FAFC',    // Warmer white for dark mode
                        },
                        secondary: {
                            DEFAULT: '#4A5568', // Medium gray for hierarchy
                            dark: '#E2E8F0',    // Lighter gray for dark mode
                        },
                    },
                    border: {
                        DEFAULT: '#CBD5E1', // Slightly deeper border color
                        dark: '#4B5563',    // gray-600 (improved contrast)
                    },
                    hover: {
                        DEFAULT: '#CBD5E1', // More visible but gentle hover
                        light: '#E2E8F0',   // Subtle hover effect
                        dark: '#374151',    // Dark mode hover
                    },
                    panel: {
                        DEFAULT: '#E2E8F0', // Matching background for consistency
                        secondary: '#EDF2F7', // For nested panels
                        dark: '#374151',    // Dark mode panels
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
