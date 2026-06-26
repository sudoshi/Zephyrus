import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import flowbite from 'flowbite/plugin';
const { heroui } = require("@heroui/react");

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{js,jsx,ts,tsx}',
        './node_modules/@heroui/theme/dist/**/*.{js,ts,jsx,tsx}',
        './node_modules/flowbite-react/**/*.{js,jsx,ts,tsx}',
        './node_modules/flowbite/**/*.js'
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                'gray-850': '#1e2330', // Dark mode deeper gray
                'gray-750': '#2d3748', // Dark mode medium-deep gray
                'gray-150': '#edf2f7', // Light mode subtle gray
                'gray-100': '#f7fafc', // Light mode very subtle gray
                healthcare: {
                    background: {
                        DEFAULT: '#f8fafc',
                        dark: '#0f172a'
                    },
                    text: {
                        primary: {
                            DEFAULT: '#0f172a',
                            dark: '#f8fafc'
                        },
                        secondary: {
                            DEFAULT: '#475569',
                            dark: '#94a3b8'
                        }
                    },
                    surface: {
                        DEFAULT: '#ffffff',
                        dark: '#1e293b',
                        hover: {
                            DEFAULT: '#f8fafc',
                            dark: '#334155'
                        }
                    },
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
                        DEFAULT: '#059669', // light: saturated green reads on white
                        dark: '#2DD4BF',    // dark: DESIGN.md teal (rationed-urgency vocabulary)
                    },
                    teal: {
                        DEFAULT: '#0D9488', // Teal for Monitoring
                        dark: '#14B8A6',
                    },
                    critical: {
                        DEFAULT: '#DC2626',
                        dark: '#E85A6B',    // DESIGN.md coral (reserved for real breaches)
                    },
                    warning: {
                        DEFAULT: '#D97706',
                        dark: '#E5A84B',    // DESIGN.md amber
                    },
                    info: {
                        DEFAULT: '#0284C7',
                        dark: '#60A5FA',    // DESIGN.md sky
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
                // Density-tuned scale — aligned to the Acumenus Clinical token scale
                // (tokens-base.css) for dense operational dashboards. Body kept at a
                // 13px "compromise" (one notch tighter than the old 14/16px scale,
                // one notch looser than the full 12px token scale) for sustained
                // readability on clinical workstations.
                xs: ['0.6875rem', '1rem'],      // 11px / 16
                sm: ['0.8125rem', '1.125rem'],  // 13px / 18
                base: ['0.875rem', '1.25rem'],  // 14px / 20
                lg: ['1rem', '1.5rem'],         // 16px / 24
                xl: ['1.125rem', '1.625rem'],   // 18px / 26
                '2xl': ['1.375rem', '1.75rem'], // 22px / 28
                '3xl': ['1.75rem', '2.125rem'], // 28px / 34
                '4xl': ['2.25rem', '2.5rem'],   // 36px / 40
                '5xl': ['3rem', '1'],           // 48px
                '6xl': ['3.75rem', '1'],        // 60px
            },
            spacing: {
                // Existing spacing
                touch: '44px',
                // Additional spacing scales
                18: '4.5rem', // 72px
                22: '5.5rem', // 88px
                26: '6.5rem', // 104px
                30: '7.5rem', // 120px
                192: '48rem', // 768px - for double height charts
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
            zIndex: {
                '55': '55',
            },
        },
    },

    darkMode: "class",
    plugins: [
    forms({
        strategy: 'class',
    }),
    heroui(),
    require('flowbite/plugin'),
],
};
