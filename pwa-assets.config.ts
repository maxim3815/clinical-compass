import {
    defineConfig,
    minimal2023Preset,
} from '@vite-pwa/assets-generator/config';

// Иконки приложения делаются из public/favicon.svg командой npm run icons
// и коммитятся в public/. Фон квадратных иконок — цвет круга из SVG, а отступ
// оставляет кольцо компаса внутри безопасной зоны maskable (круг в 80% стороны).
const background = '#0f766e';
const padding = 0.1;

export default defineConfig({
    images: ['public/favicon.svg'],
    preset: {
        ...minimal2023Preset,
        transparent: {
            sizes: [192, 512],
            favicons: [[48, 'favicon.ico']],
        },
        maskable: {
            sizes: [512],
            padding,
            resizeOptions: { background },
        },
        apple: {
            sizes: [180],
            padding,
            resizeOptions: { background },
        },
        assetName: (type, size) => {
            switch (type) {
                case 'apple':
                    return 'apple-touch-icon.png';
                case 'maskable':
                    return `maskable-icon-${size.width}x${size.height}.png`;
                default:
                    return `pwa-${size.width}x${size.height}.png`;
            }
        },
    },
});
