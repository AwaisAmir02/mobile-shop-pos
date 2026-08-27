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
                    50: '#ecfdf7',
                    100: '#d1faec',
                    200: '#a7f3da',
                    300: '#6ee7c3',
                    400: '#34d3a3',
                    500: '#0fb884',
                    600: '#049669',
                    700: '#037a56',
                    800: '#046049',
                    900: '#054e3c',
                    950: '#012c22',
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
