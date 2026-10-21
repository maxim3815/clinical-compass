<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { BellRing } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import IosInstallSteps from '@/components/IosInstallSteps.vue';
import { Button } from '@/components/ui/button';
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

        <div
            v-if="state !== 'loading'"
            class="flex flex-col items-start gap-3 text-sm"
            data-test="push-settings"
            :data-state="state"
        >
            <template v-if="state === 'on'">
                <p class="flex items-center gap-2 font-medium">
                    <BellRing class="size-4 text-success" />
                    Уведомления включены на этом устройстве
                </p>
                <p v-if="isPatient" class="text-muted-foreground">
                    Напоминание придёт, когда наступит время приёма. Из него
                    можно сразу отметить приём или отложить напоминание на 15
                    минут.
                </p>
                <Button
                    variant="outline"
                    :disabled="busy"
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
                    :disabled="busy"
                    data-test="push-enable"
                    @click="enable"
                >
                    Включить
                </Button>
            </template>

            <p v-else-if="state === 'denied'" data-test="push-denied">
                Уведомления для этого сайта запрещены в настройках браузера.
                Разрешите их в настройках сайта (значок слева от адреса) или в
                настройках приложения на телефоне, затем обновите страницу.
            </p>

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
