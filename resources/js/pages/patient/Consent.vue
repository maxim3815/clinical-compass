<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ConsentText from '@/components/ConsentText.vue';
import { consent } from '@/routes/patient';
import { edit as profile } from '@/routes/profile';

/**
 * Согласие, которое пациент принял при регистрации: перечитать из настроек, не выходя из кабинета.
 * Гостевая страница /consent с тем же текстом — для регистрации.
 */
defineProps<{
    // «25 октября 2026»: когда пациент дал согласие.
    consentedOn: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Настройки', href: profile() },
            { title: 'Согласие на обработку данных', href: consent() },
        ],
    },
});
</script>

<template>
    <Head title="Согласие на обработку персональных данных" />

    <div class="flex flex-col gap-4 p-4" data-test="patient-consent">
        <h1 class="text-2xl font-semibold tracking-tight">
            Согласие на обработку персональных данных
        </h1>
        <ConsentText signed :consented-on="consentedOn" />
    </div>
</template>
