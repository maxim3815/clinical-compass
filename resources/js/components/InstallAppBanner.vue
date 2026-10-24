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
        class="flex items-start gap-4 rounded-2xl border bg-card p-4 shadow-xs"
        data-test="install-banner"
    >
        <div
            class="flex size-11 shrink-0 items-center justify-center rounded-full bg-secondary text-secondary-foreground"
        >
            <Smartphone class="size-6" />
        </div>
        <div class="flex min-w-0 flex-1 flex-col items-start gap-3">
            <div class="flex flex-col gap-1">
                <h2 class="text-lg font-semibold">Установите приложение</h2>
                <p v-if="method === 'ios'">
                    Приложение откроется с экрана «Домой» одним нажатием, а на
                    iPhone напоминания о приёме приходят только в установленное
                    приложение.
                </p>
                <p v-else>
                    На экране телефона появится значок «Компаса», как у других
                    приложений.
                </p>
            </div>

            <IosInstallSteps v-if="method === 'ios'" />

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="method === 'prompt'"
                    size="lg"
                    data-test="install-button"
                    @click="install"
                >
                    Установить
                </Button>
                <Button
                    size="lg"
                    :variant="method === 'prompt' ? 'ghost' : 'outline'"
                    :class="{ 'text-primary': method === 'prompt' }"
                    data-test="install-dismiss"
                    @click="dismiss"
                >
                    Не сейчас
                </Button>
            </div>
        </div>
    </section>
</template>
