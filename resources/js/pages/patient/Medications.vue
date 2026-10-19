<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import PrescriptionDetails from '@/components/PrescriptionDetails.vue';
import { medications } from '@/routes/patient';
import type { Prescription } from '@/types';

defineProps<{
    prescriptions: Prescription[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Мои препараты',
                href: medications(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Мои препараты" />

    <div class="flex max-w-xl flex-col gap-4 p-4">
        <Heading
            title="Мои препараты"
            description="Что и как принимать по назначению врача."
        />

        <p
            v-if="prescriptions.length === 0"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
            data-test="no-prescriptions"
        >
            Врач пока не назначил препараты.
        </p>

        <PrescriptionDetails
            v-for="prescription in prescriptions"
            :key="prescription.id"
            :prescription="prescription"
        />
    </div>
</template>
