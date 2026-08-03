import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
            detectTls: false,
        }),
        tailwindcss(),
        vue({
            template: {
                // Vidstack ships web components (<media-player>, <media-video-layout>, ...).
                // Without this Vue tries to resolve them as Vue components and warns.
                compilerOptions: {
                    isCustomElement: (tag) => tag.startsWith('media-'),
                },
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
