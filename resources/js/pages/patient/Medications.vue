<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Clock } from '@lucide/vue';
import IntakeTimeController from '@/actions/App/Http/Controllers/Patient/IntakeTimeController';
import Heading from '@/components/Heading.vue';
import PrescriptionDetails from '@/components/PrescriptionDetails.vue';
import { Button } from '@/components/ui/button';
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

function hasTimes(prescription: Prescription): boolean {
    return prescription.intakes.every((intake) => intake.time !== null);
}
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
        >
            <template v-if="prescription.kind === 'regular'" #actions>
                <Button
                    :variant="hasTimes(prescription) ? 'outline' : 'default'"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="IntakeTimeController.edit(prescription.id)"
                        data-test="intake-times-button"
                    >
                        <Clock />
                        {{
                            hasTimes(prescription)
                                ? 'Изменить время'
                                : 'Выбрать время'
                        }}
                    </Link>
                </Button>
            </template>
        </PrescriptionDetails>
    </div>
</template>
