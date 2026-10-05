import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
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
            },
        },
    },

    plugins: [forms],
};
