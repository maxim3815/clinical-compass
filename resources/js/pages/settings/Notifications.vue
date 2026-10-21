<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { BellRing } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import IosInstallSteps from '@/components/IosInstallSteps.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { isIos, isStandalone } from '@/composables/useInstallApp';
import { usePushNotifications } from '@/composables/usePushNotifications';
import { edit } from '@/routes/notifications';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Уведомления',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const isPatient = computed(() => page.props.auth.user.role === 'patient');

const { state, busy, error, enable, disable } = usePushNotifications();

// Где разрешать уведомления: сначала инструкция для этого устройства, остальные свёрнуты.
type Device = 'browser' | 'android' | 'ios';

const device = ref<Device>('browser');

onMounted(() => {
    device.value = isIos() ? 'ios' : isStandalone() ? 'android' : 'browser';
});

const steps: Record<Device, string> = {
    browser:
        'В браузере нажмите значок слева от адреса сайта, затем «Разрешения» или «Уведомления» и выберите «Разрешить».',
    android:
        'Удерживайте значок приложения на экране телефона, откройте «О приложении», затем «Уведомления» и включите их.',
    ios: 'Откройте «Настройки» телефона, затем «Уведомления», выберите «Компас» и включите «Допуск уведомлений».',
};

const otherDevices = computed(() =>
    (Object.keys(steps) as Device[]).filter((key) => key !== device.value),
);

const deviceNames: Record<Device, string> = {
    browser: 'В браузере',
    android: 'В приложении на Android',
    ios: 'На iPhone',
};

// В установленном приложении нет кнопки браузера «Обновить».
function reload(): void {
    window.location.reload();
}
</script>

<template>
    <Head title="Уведомления" />

    <h1 class="sr-only">Уведомления</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Уведомления"
            :description="
                isPatient
                    ? 'Напоминания о приёме и сообщения врача на этом устройстве'
                    : 'Сообщения пациентов на этом устройстве'
            "
        />

        <p
            v-if="state === 'loading'"
            class="flex items-center gap-2 text-muted-foreground"
        >
            <Spinner class="size-5" />
            Проверяем уведомления на этом устройстве…
        </p>

        <div
            v-else
            class="flex flex-col items-start gap-3"
            data-test="push-settings"
            :data-state="state"
        >
            <template v-if="state === 'on'">
                <p class="flex items-center gap-2 font-medium">
                    <BellRing class="size-5 text-success" />
                    Уведомления включены на этом устройстве
                </p>
                <p v-if="isPatient" class="text-muted-foreground">
                    Напоминание придёт, когда наступит время приёма. Из него
                    можно сразу отметить приём или отложить напоминание на 15
                    минут.
                </p>
                <Button
                    variant="outline"
                    size="lg"
                    :disabled="busy"
                    :aria-busy="busy"
                    data-test="push-disable"
                    @click="disable"
                >
                    Выключить
                </Button>
            </template>

            <template v-else-if="state === 'off'">
                <p v-if="isPatient">
                    Включите уведомления: напомним о приёме в нужное время и
                    сообщим, когда врач ответит. Уведомления включаются на
                    каждом устройстве отдельно.
                </p>
                <p v-else>
                    Включите уведомления: сообщим, когда пациент напишет в чат,
                    даже если кабинет закрыт. Уведомления включаются на каждом
                    устройстве отдельно.
                </p>
                <Button
                    size="lg"
                    :disabled="busy"
                    :aria-busy="busy"
                    data-test="push-enable"
                    @click="enable"
                >
                    <Spinner v-if="busy" class="size-5" />
                    Включить
                </Button>
            </template>

            <div
                v-else-if="state === 'denied'"
                class="flex flex-col items-start gap-3"
                data-test="push-denied"
            >
                <p>
                    Уведомления на этом устройстве запрещены. Чтобы их
                    разрешить:
                </p>
                <p class="font-medium">{{ steps[device] }}</p>
                <p>Потом обновите эту страницу.</p>
                <Button variant="outline" size="lg" @click="reload">
                    Обновить страницу
                </Button>
                <details class="w-full">
                    <summary
                        class="flex min-h-11 cursor-pointer items-center rounded-lg font-medium text-primary outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                    >
                        Другое устройство
                    </summary>
                    <ul class="flex flex-col gap-2 pt-1">
                        <li v-for="other in otherDevices" :key="other">
                            <span class="font-medium"
                                >{{ deviceNames[other] }}:</span
                            >
                            {{
                                steps[other].charAt(0).toLowerCase() +
                                steps[other].slice(1)
                            }}
                        </li>
                    </ul>
                </details>
            </div>

            <template v-else-if="state === 'install'">
                <p>
                    На iPhone уведомления приходят только в установленное
                    приложение. Добавьте его на экран «Домой», откройте оттуда и
                    включите уведомления на этой странице.
                </p>
                <IosInstallSteps />
            </template>

            <p
                v-else
                class="text-muted-foreground"
                data-test="push-unsupported"
            >
                Этот браузер не показывает уведомления. На телефоне откройте
                приложение в Chrome на Android или установите его на iPhone.
            </p>

            <InputError :message="error ?? undefined" />
        </div>
    </div>
</template>
