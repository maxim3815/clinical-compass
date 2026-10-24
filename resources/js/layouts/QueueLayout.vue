<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Toaster } from '@/components/ui/sonner';

/**
 * Страница у двери терапевта: пациент в очереди отвечает без входа. Узкая колонка, как в кабинете
 * пациента, но без вкладок и без ссылок, которые увели бы с недозаполненных ответов.
 */
const name = usePage().props.name;

// Текст пациента не мельче 16 px, и в уведомлениях тоже. Ошибка отправки висит дольше: в очереди
// её легко пропустить.
const toastOptions = {
    classes: { title: 'text-base leading-snug font-medium' },
    duration: 10000,
};
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background">
        <header class="border-b bg-card">
            <div
                class="mx-auto flex h-14 w-full max-w-xl items-center gap-3 px-4"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-xs"
                >
                    <AppLogoIcon class="size-6" />
                </span>
                <span class="text-lg font-semibold tracking-tight">{{
                    name
                }}</span>
            </div>
        </header>
        <!-- Текст пациента не мельче 16 px: подписи и ошибки полей тоже. -->
        <main
            class="mx-auto w-full max-w-xl flex-1 pb-[calc(env(safe-area-inset-bottom)+2rem)] [&_[data-slot=input-error]]:text-base [&_[data-slot=label]]:text-base"
        >
            <slot />
        </main>
        <Toaster
            position="top-center"
            container-aria-label="Уведомления"
            :toast-options="toastOptions"
        />
    </div>
</template>
