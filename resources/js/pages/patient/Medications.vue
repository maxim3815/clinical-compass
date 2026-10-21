<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Clock, Plus, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import IntakeTimeController from '@/actions/App/Http/Controllers/Patient/IntakeTimeController';
import OwnMedicationController from '@/actions/App/Http/Controllers/Patient/OwnMedicationController';
import PrescriptionDetails from '@/components/PrescriptionDetails.vue';
import StopMedicationDialog from '@/components/StopMedicationDialog.vue';
import { Button } from '@/components/ui/button';
import { medications } from '@/routes/patient';
import type { Prescription } from '@/types';

const props = defineProps<{
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

// Назначения, у которых пациент ещё не выбрал время, наверху: без времени нет напоминаний.
const doctorPrescriptions = computed(() => [
    ...props.prescriptions.filter((prescription) => !hasTimes(prescription)),
    ...props.prescriptions.filter((prescription) => hasTimes(prescription)),
]);

function titleId(prescription: Prescription): string {
    return `prescription-${prescription.id}-title`;
}
</script>

<template>
    <Head title="Мои препараты" />

    <div class="flex flex-col gap-8 p-4">
        <h1 class="text-2xl font-semibold tracking-tight">Мои препараты</h1>

        <section class="flex flex-col gap-3" data-test="doctor-prescriptions">
            <h2 class="text-xl font-semibold">Назначил врач</h2>

            <p
                v-if="prescriptions.length === 0"
                class="rounded-2xl border border-dashed p-4 text-muted-foreground"
                data-test="no-prescriptions"
            >
                Врач пока не назначил препараты.
            </p>

            <PrescriptionDetails
                v-for="prescription in doctorPrescriptions"
                :key="prescription.id"
                :prescription="prescription"
            >
                <template
                    v-if="
                        prescription.kind === 'regular' &&
                        !hasTimes(prescription)
                    "
                    #notice
                >
                    <p
                        class="flex gap-2 rounded-xl border border-warning-border bg-warning-subtle p-3"
                        data-test="no-times-notice"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-5 shrink-0 text-warning"
                        />
                        Выберите время приёма: без него не будет напоминаний и
                        отметок на «Сегодня».
                    </p>
                </template>
                <template v-if="prescription.kind === 'regular'" #actions>
                    <Button
                        :variant="
                            hasTimes(prescription) ? 'outline' : 'default'
                        "
                        size="lg"
                        as-child
                    >
                        <Link
                            :href="IntakeTimeController.edit(prescription.id)"
                            :aria-describedby="titleId(prescription)"
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

        <section class="flex flex-col gap-3" data-test="own-medications">
            <div class="flex flex-col gap-1">
                <h2 class="text-xl font-semibold">Добавленные мной</h2>
                <p class="text-muted-foreground">
                    Препараты от других врачей. Вы отмечаете их приём, а ваш
                    врач видит, что вы их принимаете.
                </p>
            </div>

            <Button size="lg" variant="secondary" as-child>
                <Link
                    :href="OwnMedicationController.create()"
                    data-test="add-medication-button"
                >
                    <Plus />
                    Добавить препарат
                </Link>
            </Button>

            <PrescriptionDetails
                v-for="prescription in ownMedications"
                :key="prescription.id"
                :prescription="prescription"
            >
                <template #actions>
                    <Button variant="outline" size="lg" as-child>
                        <Link
                            :href="
                                OwnMedicationController.edit(prescription.id)
                            "
                            :aria-describedby="titleId(prescription)"
                        >
                            Изменить
                        </Link>
                    </Button>
                    <Button
                        variant="ghost"
                        size="lg"
                        class="text-primary"
                        :aria-describedby="titleId(prescription)"
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
