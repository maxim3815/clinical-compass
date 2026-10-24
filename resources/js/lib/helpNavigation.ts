import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Экран помощи открыт кнопкой в шапке изнутри приложения: «Назад» на нём — шаг назад по истории
 * браузера. Так пациент возвращается туда, где был, в том числе в опросник с уже выбранными
 * ответами. Если экран открыт ссылкой или перезагружен, «Назад» ведёт на «Сегодня».
 */
export const helpOpenedInApp = ref(false);

if (typeof window !== 'undefined') {
    router.on('navigate', (event) => {
        if (event.detail.page.component !== 'patient/Help') {
            helpOpenedInApp.value = false;
        }
    });
}
