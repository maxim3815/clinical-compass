import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref, shallowRef } from 'vue';

// Событие Chrome и Android: приложение можно установить. В типах DOM его нет.
type BeforeInstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

/**
 * Как поставить приложение: системный диалог браузера (`prompt`), шаги через
 * «Поделиться» на iPhone и iPad (`ios`) или в этом браузере никак (`null`).
 */
export type InstallMethod = 'prompt' | 'ios' | null;

export type UseInstallAppReturn = {
    /** Страница смонтирована: до этого (и при SSR) ничего не показываем. */
    ready: Ref<boolean>;
    /** Приложение открыто как установленное или только что установлено. */
    installed: ComputedRef<boolean>;
    method: ComputedRef<InstallMethod>;
    /** Баннер на «Сегодня»: телефон, не установлено и «Не сейчас» не нажимали 7 дней. */
    showBanner: ComputedRef<boolean>;
    install: () => Promise<void>;
    dismiss: () => void;
};

const BANNER_DISMISSED_KEY = 'install-banner-dismissed-at';
const BANNER_PAUSE_MS = 7 * 24 * 60 * 60 * 1000;

// Браузер присылает событие один раз и рано, ещё до монтирования страницы,
// поэтому слушаем его с загрузки приложения.
const installPrompt = shallowRef<BeforeInstallPromptEvent | null>(null);
const justInstalled = ref(false);

// «Не сейчас» общий для всех экземпляров: баннер пушей ждёт, пока скрыт баннер установки.
const dismissed = ref(false);

export function initializeInstallPrompt(): void {
    if (import.meta.env.SSR) {
        return;
    }

    window.addEventListener('beforeinstallprompt', (event) => {
        // Вместо мини-панели браузера пациент видит наш баннер.
        event.preventDefault();
        installPrompt.value = event as BeforeInstallPromptEvent;
    });

    window.addEventListener('appinstalled', () => {
        installPrompt.value = null;
        justInstalled.value = true;
    });
}

export function isStandalone(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

// На iOS все браузеры работают на движке Safari и ставят приложение через «Поделиться».
// iPad представляется компьютером Mac, но у него сенсорный экран.
export function isIos(): boolean {
    const userAgent = navigator.userAgent;

    return (
        /iPhone|iPad|iPod/.test(userAgent) ||
        (userAgent.includes('Macintosh') && navigator.maxTouchPoints > 1)
    );
}

function isPhone(): boolean {
    return window.matchMedia('(max-width: 767px), (pointer: coarse)').matches;
}

function bannerDismissedRecently(): boolean {
    try {
        const dismissedAt = Number(localStorage.getItem(BANNER_DISMISSED_KEY));

        return dismissedAt > 0 && Date.now() - dismissedAt < BANNER_PAUSE_MS;
    } catch {
        // Без хранилища баннер просто показывается.
        return false;
    }
}

export function useInstallApp(): UseInstallAppReturn {
    const ready = ref(false);
    const standalone = ref(false);
    const ios = ref(false);
    const phone = ref(false);

    onMounted(() => {
        standalone.value = isStandalone();
        ios.value = isIos();
        phone.value = isPhone();
        dismissed.value = bannerDismissedRecently();
        ready.value = true;
    });

    const installed = computed(() => standalone.value || justInstalled.value);

    const method = computed<InstallMethod>(() => {
        if (!ready.value || installed.value) {
            return null;
        }

        if (installPrompt.value) {
            return 'prompt';
        }

        return ios.value ? 'ios' : null;
    });

    const showBanner = computed(
        () => method.value !== null && phone.value && !dismissed.value,
    );

    async function install(): Promise<void> {
        const event = installPrompt.value;

        if (!event) {
            return;
        }

        await event.prompt();
        const { outcome } = await event.userChoice;

        // Диалог показывается один раз на событие: повторить его можно только после
        // нового beforeinstallprompt.
        installPrompt.value = null;

        if (outcome === 'accepted') {
            justInstalled.value = true;
        }
    }

    function dismiss(): void {
        dismissed.value = true;

        try {
            localStorage.setItem(BANNER_DISMISSED_KEY, String(Date.now()));
        } catch {
            // Без хранилища баннер скрыт только до перезагрузки страницы.
        }
    }

    return { ready, installed, method, showBanner, install, dismiss };
}
