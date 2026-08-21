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
                    50: '#effdf9',
                    100: '#c9f7ea',
                    200: '#94efd6',
                    300: '#5ee0c0',
                    400: '#2dc9a8',
                    500: '#14b090',
                    600: '#0c8f76',
                    700: '#0c7260',
                    800: '#0e5b4e',
                    900: '#0e4b42',
                    950: '#052b26',
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
