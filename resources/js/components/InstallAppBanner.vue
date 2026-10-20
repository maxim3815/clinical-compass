<script setup lang="ts">
import { Smartphone } from '@lucide/vue';
import IosInstallSteps from '@/components/IosInstallSteps.vue';
import { Button } from '@/components/ui/button';
import { useInstallApp } from '@/composables/useInstallApp';

const { method, showBanner, install, dismiss } = useInstallApp();
</script>

<template>
    <section
        v-if="showBanner"
        class="flex items-start gap-4 rounded-lg border border-primary/40 p-4"
        data-test="install-banner"
    >
        <div
            class="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted"
        >
            <Smartphone class="size-5 text-muted-foreground" />
        </div>
        <div class="flex min-w-0 flex-col items-start gap-3">
            <div class="flex flex-col gap-1">
                <h2 class="font-medium">Установите приложение</h2>
                <p v-if="method === 'ios'" class="text-sm">
                    Приложение откроется с экрана «Домой» одним нажатием, а на
                    iPhone напоминания о приёме приходят только в установленное
                    приложение.
                </p>
                <p v-else class="text-sm">
                    Приложение откроется с экрана «Домой» одним нажатием.
                </p>
            </div>

            <IosInstallSteps v-if="method === 'ios'" />

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="method === 'prompt'"
                    size="sm"
                    data-test="install-button"
                    @click="install"
                >
                    Установить
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    data-test="install-dismiss"
                    @click="dismiss"
                >
                    Не сейчас
                </Button>
            </div>
        </div>
    </section>
</template>
