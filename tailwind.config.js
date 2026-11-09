import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
// App design tokens (docs/design-system.md). Each name reads a CSS variable set in
// resources/css/app.css, light by default with a dark set ready under
// [data-theme="dark"]. Screens use these names; the marketing site keeps using the
// brand/accent/slate palette directly, so these never change how the site looks.
const token = (name) => `rgb(var(--${name}) / <alpha-value>)`;

export default {
    darkMode: ['selector', '[data-theme="dark"]'],

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                page: token('page'),
                surface: { DEFAULT: token('surface'), raised: token('surface-raised'), sunken: token('surface-sunken') },
                line: { DEFAULT: token('line'), strong: token('line-strong') },
                ink: { DEFAULT: token('ink'), muted: token('ink-muted'), faint: token('ink-faint') },
                primary: { DEFAULT: token('primary'), hover: token('primary-hover'), soft: token('primary-soft') },
                'on-primary': { DEFAULT: token('on-primary'), soft: token('on-primary-soft') },
                link: token('link'),
                emphasis: { DEFAULT: token('emphasis'), ink: token('on-emphasis'), muted: token('on-emphasis-muted') },
                sale: { DEFAULT: token('sale'), hover: token('sale-hover'), ink: token('on-sale') },
                success: { DEFAULT: token('success'), soft: token('success-soft') },
                warning: { DEFAULT: token('warning'), soft: token('warning-soft') },
                danger: { DEFAULT: token('danger'), soft: token('danger-soft'), solid: token('danger-solid'), 'solid-ink': token('on-danger-solid') },
                info: { DEFAULT: token('info'), soft: token('info-soft') },
                scrim: token('scrim'),
                // The navy sidebar's own set (D12); see resources/css/app.css.
                sidebar: {
                    DEFAULT: token('sidebar'),
                    ink: { DEFAULT: token('on-sidebar'), strong: token('on-sidebar-strong'), soft: token('on-sidebar-soft'), muted: token('on-sidebar-muted'), faint: token('on-sidebar-faint') },
                    active: { DEFAULT: token('sidebar-active'), ink: token('on-sidebar-active') },
                    focus: token('sidebar-focus'),
                },
                brand: {
                    50: '#f0f3fa',
                    100: '#d9e0f2',
                    200: '#b3c2e5',
                    300: '#8ca3d8',
                    400: '#597ac7',
                    500: '#2652b6',
                    600: '#0033a9',
                    700: '#012d8e',
                    800: '#012772',
                    900: '#022157',
                    950: '#021b3c',
                },
                accent: {
                    50: '#fff3eb',
                    100: '#ffe0cc',
                    200: '#ffc49f',
                    300: '#fea56c',
                    400: '#fc9e44',
                    500: '#fe6402',
                    600: '#d65002',
                    700: '#ab3e02',
                    800: '#802e03',
                    900: '#572005',
                },
            },
            fontSize: {
                'display-sm': ['1.75rem', { lineHeight: '2.25rem', fontWeight: '700' }],
                display: ['2.5rem', { lineHeight: '3rem', fontWeight: '700' }],
            },
            boxShadow: {
                card: '0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06)',
                // App elevation, tinted by --shadow so it can deepen in dark mode.
                soft: '0 1px 2px 0 rgb(var(--shadow) / 0.05)',
                float: '0 24px 48px -12px rgb(var(--shadow) / 0.18), 0 0 0 1px rgb(var(--shadow) / 0.05)',
            },
            zIndex: {
                menu: '60',
                dialog: '65',
                toast: '70',
            },
        },
    },

    plugins: [forms],
};
