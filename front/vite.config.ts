import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import path from 'path';
import Components from 'unplugin-vue-components/vite'

export default defineConfig({
    plugins: [
        vue(),
        Components({
            dirs: ['src/components','src/layouts/partials'],
            extensions: ['vue'],
            deep: true,
            dts: true,
        })
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './src'),
        },
    },
    server: {
        port: 5174,
    },
    test: {
        environment: 'jsdom',
        include: ['src/**/*.{test,spec}.ts'],
        css: false,
        setupFiles: ['src/test/setup.ts'],
    }
});
