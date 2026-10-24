<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CircleCheck } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import IosInstallSteps from '@/components/IosInstallSteps.vue';
import { Button } from '@/components/ui/button';
import { useInstallApp } from '@/composables/useInstallApp';
import { edit } from '@/routes/app';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Приложение',
                href: edit(),
            },
        ],
    },
});

const { ready, installed, method, install } = useInstallApp();
</script>

<template>
    <Head title="Приложение" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Приложение"
            description="Значок «Компаса» на экране телефона"
        />

        <div v-if="ready" class="flex flex-col items-start gap-3">
            <p
                v-if="installed"
                class="flex items-center gap-2 font-medium"
                data-test="app-installed"
            >
                <CircleCheck class="size-5 text-success" />
                Приложение установлено
            </p>

            <template v-else-if="method === 'prompt'">
                <p>
                    Установите приложение: оно будет открываться значком, как
                    другие приложения.
                </p>
                <Button size="lg" data-test="install-button" @click="install">
                    Установить
                </Button>
            </template>

            <template v-else-if="method === 'ios'">
                <p>
                    Добавьте приложение на экран «Домой»: оно откроется одним
                    нажатием, а на iPhone напоминания о приёме приходят только в
                    установленное приложение.
                </p>
                <IosInstallSteps />
            </template>

            <p
                v-else
                class="text-muted-foreground"
                data-test="install-unavailable"
            >
                Этот браузер не предлагает установить приложение. На телефоне
                откройте сайт в Chrome на Android или в Safari на iPhone. Если
                приложение уже установлено, запустите его значком на экране
                телефона.
            </p>
        </div>
    </div>
</template>
