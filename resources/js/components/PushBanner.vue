<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, BellOff, BellRing } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useInstallApp } from '@/composables/useInstallApp';
import { usePushNotifications } from '@/composables/usePushNotifications';
import { edit as notifications } from '@/routes/notifications';

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

// Чем закончился запрос разрешения: баннер не пропадает молча, а говорит, что вышло.
const outcome = ref<'on' | 'denied' | null>(null);

const outcomeTitle = useTemplateRef<HTMLElement>('outcomeTitle');

// Кнопка «Включить» пропадает: фокус переходит к итогу, и скринридер его читает.
async function turnOn(): Promise<void> {
    await enable();

    if (state.value === 'on' || state.value === 'denied') {
        outcome.value = state.value;
        await nextTick();
        outcomeTitle.value?.focus();
    }
}

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
        v-if="outcome"
        class="flex items-start gap-4 rounded-2xl border bg-card p-4 shadow-xs"
        role="status"
        data-test="push-banner-outcome"
    >
        <div
            class="flex size-11 shrink-0 items-center justify-center rounded-full"
            :class="
                outcome === 'on'
                    ? 'bg-success-subtle text-success'
                    : 'bg-muted text-muted-foreground'
            "
        >
            <BellRing v-if="outcome === 'on'" class="size-6" />
            <BellOff v-else class="size-6" />
        </div>
        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <h2
                ref="outcomeTitle"
                class="rounded-sm text-lg font-semibold outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                tabindex="-1"
            >
                {{
                    outcome === 'on'
                        ? isPatient
                            ? 'Напоминания включены'
                            : 'Уведомления включены'
                        : 'Браузер запретил уведомления'
                }}
            </h2>
            <p>
                {{
                    outcome === 'on'
                        ? 'Выключить их можно в настройках, в разделе'
                        : 'Как их разрешить, написано в настройках, в разделе'
                }}
                <Link
                    :href="notifications()"
                    class="rounded-sm font-medium text-primary underline underline-offset-4 outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                    >«Уведомления»</Link
                >.
            </p>
        </div>
    </section>

    <section
        v-else-if="visible"
        class="flex items-start gap-4 rounded-2xl border bg-card p-4 shadow-xs"
        data-test="push-banner"
    >
        <div
            class="flex size-11 shrink-0 items-center justify-center rounded-full bg-secondary text-secondary-foreground"
        >
            <Bell class="size-6" />
        </div>
        <div class="flex min-w-0 flex-1 flex-col items-start gap-3">
            <div class="flex flex-col gap-1">
                <h2 class="text-lg font-semibold">
                    {{
                        isPatient
                            ? 'Включите напоминания'
                            : 'Включите уведомления о сообщениях'
                    }}
                </h2>
                <p v-if="isPatient">
                    Напомним, когда придёт время приёма. Отметить приём можно
                    прямо из уведомления.
                </p>
                <p v-else>
                    Сообщим, когда пациент напишет в чат, даже если кабинет
                    закрыт.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    size="lg"
                    :disabled="busy"
                    :aria-busy="busy"
                    data-test="push-banner-enable"
                    @click="turnOn"
                >
                    <Spinner v-if="busy" class="size-5" />
                    Включить
                </Button>
                <Button
                    size="lg"
                    variant="ghost"
                    class="text-primary"
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
