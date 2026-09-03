import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.tsx'], refresh: true }),
        react(),
        tailwindcss(),
        wayfinder(),
    ],
    resolve: { alias: { '@': '/resources/js' } },
    server: { cors: true, watch: { ignored: ['**/storage/framework/views/**'] } },
});
