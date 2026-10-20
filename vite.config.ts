import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
        VitePWA({
            // Свой service worker (resources/js/sw.ts): Workbox только вставляет в него
            // список предкэша. Регистрирует его приложение, манифест лежит в public/.
            strategies: 'injectManifest',
            srcDir: 'resources/js',
            filename: 'sw.ts',
            injectRegister: false,
            manifest: false,
            // Ассеты Laravel собираются в public/build, а service worker кладём в корень
            // сайта: так его scope — всё приложение.
            outDir: 'public',
            base: '/',
            scope: '/',
            injectManifest: {
                globDirectory: 'public',
                globPatterns: [
                    'build/assets/*.{js,css,woff2}',
                    'offline.html',
                    'favicon.{ico,svg}',
                    'apple-touch-icon.png',
                    'pwa-*.png',
                    'maskable-icon-*.png',
                ],
                // В имени ассетов Vite уже есть хеш, ревизия им не нужна.
                dontCacheBustURLsMatching: /^build\/assets\//,
            },
        }),
    ]),
    server: {
        // Vite работает в Docker: слушаем все интерфейсы контейнера,
        // а браузер и HMR ходят на опубликованный порт localhost.
        host: '0.0.0.0',
        port: Number(process.env.VITE_PORT ?? 5173),
        strictPort: true,
        // По этому имени php-контейнер ходит в Vite за SSR (INERTIA_SSR_HOT_URL).
        allowedHosts: ['node'],
        hmr: {
            host: 'localhost',
        },
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
        overrides: [
            {
                files: ['*.yaml', '*.yml'],
                options: {
                    tabWidth: 2,
                    singleQuote: false,
                    printWidth: 120,
                },
            },
        ],
    },
});
