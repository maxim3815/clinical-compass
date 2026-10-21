<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import PatientLayout from '@/layouts/app/PatientLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

// Пациент открывает кабинет на телефоне: у него вкладки внизу, у врача — боковое меню.
// Роль за время сессии не меняется, поэтому каркас между переходами не пересоздаётся.
const isPatient = usePage().props.auth.user.role === 'patient';
</script>

<template>
    <PatientLayout v-if="isPatient" :breadcrumbs="breadcrumbs">
        <slot />
    </PatientLayout>
    <AppSidebarLayout v-else :breadcrumbs="breadcrumbs">
        <slot />
    </AppSidebarLayout>
</template>
