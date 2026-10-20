<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { useInstallApp } from '@/composables/useInstallApp';
import { usePushNotifications } from '@/composables/usePushNotifications';

const DISMISSED_KEY = 'push-banner-dismissed-at';
const PAUSE_MS = 7 * 24 * 60 * 60 * 1000;

const page = usePage();
const isPatient = computed(() => page.props.auth.user.role === 'patient');

const { showBanner: installBannerShown } = useInstallApp();
const { state, askable, busy, error, enable } = usePushNotifications();
const dismissed = ref(true);

onMounted(() => {
    try {
        const dismissedAt = Number(localStorage.getItem(DISMISSED_KEY));

        dismissed.value =
            dismissedAt > 0 && Date.now() - dismissedAt < PAUSE_MS;
    } catch {
        // Без хранилища баннер просто показывается.
        dismissed.value = false;
    }
});

// Разрешение ещё не спрашивали и подписки нет. Пока виден баннер установки, ждём:
// два баннера подряд — слишком много.
const visible = computed(
    () =>
        state.value === 'off' &&
        askable.value &&
        !dismissed.value &&
        !installBannerShown.value,
);

function dismiss(): void {
    dismissed.value = true;

    try {
        localStorage.setItem(DISMISSED_KEY, String(Date.now()));
    } catch {
        // Без хранилища баннер скрыт только до перезагрузки страницы.
    }
}
</script>

<template>
    <section
        v-if="visible"
        class="flex items-start gap-4 rounded-lg border border-primary/40 p-4"
        data-test="push-banner"
    >
        <div
            class="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted"
        >
            <Bell class="size-5 text-muted-foreground" />
        </div>
        <div class="flex min-w-0 flex-col items-start gap-3">
            <div class="flex flex-col gap-1">
                <h2 class="font-medium">
                    {{
                        isPatient
                            ? 'Включите напоминания'
                            : 'Включите уведомления о сообщениях'
                    }}
                </h2>
                <p v-if="isPatient" class="text-sm">
                    Напомним, когда придёт время приёма. Отметить приём можно
                    прямо из уведомления.
                </p>
                <p v-else class="text-sm">
                    Сообщим, когда пациент напишет в чат, даже если кабинет
                    закрыт.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    :disabled="busy"
                    data-test="push-banner-enable"
                    @click="enable"
                >
                    Включить
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    data-test="push-banner-dismiss"
                    @click="dismiss"
                >
                    Не сейчас
                </Button>
            </div>

            <InputError :message="error ?? undefined" />
        </div>
    </section>
</template>
