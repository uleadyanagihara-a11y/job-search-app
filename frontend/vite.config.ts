import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [vue()],
    base: '/',
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },
    // ブラウザは Nginx 経由でのみ接続する。API proxy は持たせない。
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
    },
    build: {
        outDir: 'dist',
        assetsDir: 'assets',
        emptyOutDir: true,
        manifest: false,
        sourcemap: false,
    },
});
