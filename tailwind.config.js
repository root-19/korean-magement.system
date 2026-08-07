import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    /*
     * No darkMode strategy: the app is dark, full stop — the way the legacy
     * views were (`<body class="bg-gray-900">` on every page). There is no light
     * variant to switch to, so `dark:` variants are not generated at all.
     */
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './app/View/Components/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter var', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                /*
                 * Brand = the legacy yellow. Nav links, headings and stat figures
                 * were all `text-yellow-400`, so brand-400 is exactly that
                 * (#facc15) and the rest is Tailwind's yellow ramp.
                 */
                brand: {
                    50: '#fefce8',
                    100: '#fef9c3',
                    200: '#fef08a',
                    300: '#fde047',
                    400: '#facc15',
                    500: '#eab308',
                    600: '#ca8a04',
                    700: '#a16207',
                    800: '#854d0e',
                    900: '#713f12',
                    950: '#422006',
                },
                /*
                 * Accent = the legacy orange, used for nav hover
                 * (`hover:text-orange-400`) and the landing page's call to action.
                 */
                accent: {
                    50: '#fff7ed',
                    100: '#ffedd5',
                    200: '#fed7aa',
                    300: '#fdba74',
                    400: '#fb923c',
                    500: '#f97316',
                    600: '#ea580c',
                    700: '#c2410c',
                    800: '#9a3412',
                    900: '#7c2d12',
                },
                // Semantic states, so attendance colours are named by meaning.
                success: {
                    400: '#4ade80',
                    500: '#22c55e',
                    600: '#16a34a',
                },
                danger: {
                    400: '#f87171',
                    500: '#ef4444',
                    600: '#dc2626',
                },
                warning: {
                    400: '#facc15',
                    500: '#eab308',
                    600: '#ca8a04',
                },
            },
            borderRadius: {
                // Legacy panels were rounded-2xl; keeping it as the card default.
                card: '1rem',
            },
            boxShadow: {
                'glow-brand': '0 10px 30px -10px rgb(250 204 21 / 0.18)',
            },
            keyframes: {
                // Ported from the legacy dashboard's animate-fadeIn / fadeInUp.
                fadeIn: {
                    from: { opacity: '0' },
                    to: { opacity: '1' },
                },
                fadeInUp: {
                    from: { opacity: '0', transform: 'translateY(12px)' },
                    to: { opacity: '1', transform: 'translateY(0)' },
                },
            },
            animation: {
                fadeIn: 'fadeIn 0.5s ease-out both',
                fadeInUp: 'fadeInUp 0.5s ease-out both',
            },
        },
    },

    plugins: [forms],
};
