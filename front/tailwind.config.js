/** @type {import('tailwindcss').Config} */
export default {
    content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
    theme: {
        extend: {
            colors: {
                primary: {
                    DEFAULT: '#1e8665',
                    dark: '#104637',
                    light: '#4C9C82',
                },
            },
        },
    },
    plugins: [],
};
