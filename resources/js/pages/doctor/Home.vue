<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { home } from '@/routes/doctor';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Кабинет врача',
                href: home(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Кабинет врача" />

    <div class="flex flex-col gap-2 p-4">
        <h1 class="text-xl font-semibold">Кабинет врача</h1>
        <p class="text-sm text-muted-foreground">
            Здесь появится список пациентов.
        </p>
    </div>
</template>
