<script setup lang="ts">
import PatientHeader from '@/components/PatientHeader.vue';
import PatientTabBar from '@/components/PatientTabBar.vue';
import { Toaster } from '@/components/ui/sonner';
import { useNavCounters } from '@/composables/useNavCounters';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

useNavCounters();
</script>

<template>
    <!--
        Кабинет пациента: узкая колонка, внизу панель вкладок, как в приложениях на телефоне.
        --patient-chrome — сколько экрана занимают шапка, панель и нижний отступ: по нему
        страница чата берёт себе ровно остаток экрана.
    -->
    <div
        class="flex min-h-svh flex-col [--patient-chrome:calc(3.5rem+1px+var(--tab-bar-height)+env(safe-area-inset-bottom)+1rem)] [--tab-bar-height:4.25rem]"
    >
        <PatientHeader :breadcrumbs="breadcrumbs" />
        <!--
            Панель вкладок закреплена внизу: отступ не даёт ей закрыть конец страницы.
            Ошибки полей у пациента не мельче 16 px, как и остальной текст.
        -->
        <main
            class="mx-auto w-full max-w-xl flex-1 pb-[calc(var(--tab-bar-height)+env(safe-area-inset-bottom)+1rem)] [&_[data-slot=input-error]]:text-base"
        >
            <slot />
        </main>
        <PatientTabBar />
        <Toaster position="top-center" container-aria-label="Уведомления" />
    </div>
</template>
