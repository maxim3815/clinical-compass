<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { home } from '@/routes/patient';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Кабинет пациента',
                href: home(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Кабинет пациента" />

    <div class="flex flex-col gap-2 p-4">
        <h1 class="text-xl font-semibold">Кабинет пациента</h1>
        <p class="text-sm text-muted-foreground">
            Здесь появятся ваш врач и назначения.
        </p>
    </div>
</template>
