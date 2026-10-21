<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
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

const page = usePage();

// На формах панели вкладок нет: случайное касание вкладки не уносит введённое, а выйти
// можно ссылкой назад в шапке или кнопкой «Отмена».
const isForm = computed(() =>
    ['patient/MedicationForm', 'patient/IntakeTimes'].includes(page.component),
);

// Текст пациента не мельче 16 px, и в уведомлениях тоже.
const toastOptions = {
    classes: { title: 'text-base leading-snug font-medium' },
};
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
            Подписи и ошибки полей у пациента не мельче 16 px, как и остальной текст.
        -->
        <main
            class="mx-auto w-full max-w-xl flex-1 [&_[data-slot=input-error]]:text-base [&_[data-slot=label]]:text-base"
            :class="
                isForm
                    ? 'pb-[calc(env(safe-area-inset-bottom)+1rem)]'
                    : 'pb-[calc(var(--tab-bar-height)+env(safe-area-inset-bottom)+1rem)]'
            "
        >
            <slot />
        </main>
        <PatientTabBar v-if="!isForm" />
        <Toaster
            position="top-center"
            container-aria-label="Уведомления"
            :toast-options="toastOptions"
        />
    </div>
</template>
