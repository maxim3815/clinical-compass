import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        // Ошибку пациент может не успеть прочитать за 4 секунды: она висит, пока её не закроют
        // крупной кнопкой «Понятно».
        toast[data.type](
            data.message,
            data.type === 'error'
                ? {
                      duration: Infinity,
                      action: { label: 'Понятно', onClick: () => {} },
                  }
                : {},
        );
    });
}
