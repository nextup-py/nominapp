import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/attendances/mark.js',
                'resources/js/attendances/device-link.js',
                'resources/css/attendances/styles.css',
                'resources/css/attendances/device-link.css',
                'resources/js/attendances/terminal.js',
                'resources/css/attendances/terminal.css',
                'resources/css/attendances/terminal-setup.css',
                'resources/js/shared/capture-face.js',
                'resources/css/shared/capture-face.css',
                'resources/css/shared/fonts.css',
                'resources/js/planner/planner.js',
                'resources/css/planner/planner.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
        vue(),
    ],
});
