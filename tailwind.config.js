import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import colors from 'tailwindcss/colors';

/** @type {import('tailwindcss').Config} */
export default {
    mode: 'jit',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                primary: {
                    100: '#E6EFEE',
                    200: '#CBDEDD',
                    300: '#AEC6C4',
                    400: '#69A3A2',
                    500: '#005953',
                    600: '#00504B',
                    700: '#003532',
                    800: '#002825',
                    900: '#001B19',
                },
                danger: colors.rose,
                success: colors.green,
                warning: colors.yellow,
            },
        },
    },

    plugins: [forms],
};
