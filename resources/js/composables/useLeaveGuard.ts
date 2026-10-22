import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Уход со страницы с несохранённым вводом переспрашивает. Переход внутри приложения ждёт
 * ответа в окне (`LeaveConfirmDialog`), закрытие вкладки и перезагрузка — системного вопроса
 * браузера. Отправка формы не переспрашивает: это не уход.
 */
export function useLeaveGuard(isDirty: () => boolean) {
    // Куда врач или пациент собрались уйти; пока null, окно закрыто.
    const pending = ref<string | null>(null);
    let allowed = false;

    const removeGuard = router.on('before', (event) => {
        if (allowed || event.detail.visit.method !== 'get' || !isDirty()) {
            return;
        }

        event.preventDefault();
        pending.value = event.detail.visit.url.href;
    });

    function onBeforeUnload(event: BeforeUnloadEvent): void {
        if (isDirty()) {
            event.preventDefault();
        }
    }

    onMounted(() => window.addEventListener('beforeunload', onBeforeUnload));

    onBeforeUnmount(() => {
        removeGuard();
        window.removeEventListener('beforeunload', onBeforeUnload);
    });

    function leave(): void {
        const url = pending.value;

        pending.value = null;
        allowed = true;

        if (url !== null) {
            router.visit(url);
        }
    }

    function stay(): void {
        pending.value = null;
    }

    return { pending, leave, stay };
}
