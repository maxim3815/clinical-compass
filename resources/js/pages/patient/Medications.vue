<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Clock, Plus } from '@lucide/vue';
import { ref } from 'vue';
import IntakeTimeController from '@/actions/App/Http/Controllers/Patient/IntakeTimeController';
import OwnMedicationController from '@/actions/App/Http/Controllers/Patient/OwnMedicationController';
import Heading from '@/components/Heading.vue';
import PrescriptionDetails from '@/components/PrescriptionDetails.vue';
import StopMedicationDialog from '@/components/StopMedicationDialog.vue';
import { Button } from '@/components/ui/button';
import { medications } from '@/routes/patient';
import type { Prescription } from '@/types';

defineProps<{
    prescriptions: Prescription[];
    ownMedications: Prescription[];
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

const stopping = ref<Prescription | null>(null);

function hasTimes(prescription: Prescription): boolean {
    return prescription.intakes.every((intake) => intake.time !== null);
}
</script>

<template>
    <Head title="Мои препараты" />

    <div class="flex max-w-xl flex-col gap-8 p-4">
        <section class="flex flex-col gap-4" data-test="doctor-prescriptions">
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
                        :variant="
                            hasTimes(prescription) ? 'outline' : 'default'
                        "
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
        </section>

        <section class="flex flex-col gap-4" data-test="own-medications">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <Heading
                    variant="small"
                    title="Добавленные мной"
                    description="Препараты от других врачей. Вы отмечаете их приём, а ваш врач видит, что вы их принимаете."
                />
                <Button size="sm" as-child>
                    <Link
                        :href="OwnMedicationController.create()"
                        data-test="add-medication-button"
                    >
                        <Plus />
                        Добавить препарат
                    </Link>
                </Button>
            </div>

            <PrescriptionDetails
                v-for="prescription in ownMedications"
                :key="prescription.id"
                :prescription="prescription"
            >
                <template #actions>
                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="
                                OwnMedicationController.edit(prescription.id)
                            "
                        >
                            Изменить
                        </Link>
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        data-test="stop-medication-button"
                        @click="stopping = prescription"
                    >
                        Прекратить приём
                    </Button>
                </template>
            </PrescriptionDetails>
        </section>

        <StopMedicationDialog v-model:prescription="stopping" />
    </div>
</template>
