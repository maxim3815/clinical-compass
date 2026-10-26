<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    Clock,
    MessageCircle,
    Plus,
    Thermometer,
    TriangleAlert,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
import IntakeTimeController from '@/actions/App/Http/Controllers/Patient/IntakeTimeController';
import OwnMedicationController from '@/actions/App/Http/Controllers/Patient/OwnMedicationController';
import PrescriptionDetails from '@/components/PrescriptionDetails.vue';
import StopMedicationDialog from '@/components/StopMedicationDialog.vue';
import { Button } from '@/components/ui/button';
import { calendar, medications } from '@/routes/patient';
import { show as chat } from '@/routes/patient/chat';
import { index as sideEffects } from '@/routes/patient/side-effects';
import type { Prescription } from '@/types';

const props = defineProps<{
    prescriptions: Prescription[];
    ownMedications: Prescription[];
}>();

const page = usePage();

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

// Добавленный препарат встаёт в список по алфавиту и может оказаться ниже экрана вместе с
// подсказкой: список прокручивается к нему, карточка ненадолго выделена.
const added = ref<number | null>(null);

onMounted(async () => {
    const id = page.flash.addedMedication ?? null;

    if (id === null) {
        return;
    }

    added.value = id;
    await nextTick();
    const title = document.getElementById(`prescription-${id}-title`);
    title?.closest('article')?.scrollIntoView({ block: 'center' });
    // Диктор начинает с добавленного препарата и сразу читает подсказку под ним.
    title?.setAttribute('tabindex', '-1');
    title?.focus({ preventScroll: true });
    window.setTimeout(() => (added.value = null), 4000);
});
</script>

<template>
    <Head title="Мои препараты" />

    <div class="flex flex-col gap-8 p-4">
        <div class="flex flex-col items-start gap-3">
            <h1 class="text-2xl font-semibold tracking-tight">Мои препараты</h1>
            <Button
                v-if="prescriptions.length > 0 || ownMedications.length > 0"
                variant="outline"
                size="lg"
                as-child
            >
                <Link :href="calendar()" data-test="calendar-link">
                    <CalendarDays />
                    Календарь приёма
                </Link>
            </Button>
            <!-- Побочные эффекты разбирает психиатр: пока пациента ведёт терапевт, окна нет. -->
            <Button
                v-if="!page.props.withTherapist"
                variant="outline"
                size="lg"
                as-child
            >
                <Link :href="sideEffects()" data-test="side-effects-link">
                    <Thermometer />
                    Побочные эффекты
                </Link>
            </Button>
        </div>

        <section class="flex flex-col gap-3" data-test="doctor-prescriptions">
            <h2 class="text-xl font-semibold">Назначил врач</h2>

            <p
                v-if="prescriptions.length === 0"
                class="rounded-2xl border border-dashed p-4 text-muted-foreground"
                data-test="no-prescriptions"
            >
                {{
                    page.props.withTherapist
                        ? 'Препараты назначит психиатр, когда станет вашим врачом.'
                        : 'Врач пока не назначил препараты.'
                }}
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
                        Выберите время приёма, иначе не будет напоминаний и
                        препарата на экране «Сегодня».
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
                class="transition-shadow duration-700"
                :class="{
                    'ring-2 ring-primary/40': added === prescription.id,
                }"
            >
                <!-- Препарат участвует в противопоказании, по которому врач ещё не решил. Ни сочетания,
                     ни причины пациент не видит: только рассказать врачу и не бросать приём. -->
                <template v-if="prescription.tell_doctor" #notice>
                    <div
                        class="flex flex-col gap-3 rounded-xl border border-primary/30 bg-secondary p-3"
                        data-test="tell-doctor"
                    >
                        <p class="flex gap-2">
                            <MessageCircle
                                class="mt-0.5 size-5 shrink-0 text-primary"
                            />
                            Расскажите врачу, что принимаете этот препарат: врач
                            посмотрит, как он подходит к вашему лечению. До
                            разговора с врачом принимайте всё как обычно.
                        </p>
                        <!-- Пока пациента ведёт терапевт, чата нет: о препарате говорят на приёме. -->
                        <Button
                            v-if="!page.props.withTherapist"
                            variant="outline"
                            size="lg"
                            class="self-start bg-card"
                            as-child
                        >
                            <Link
                                :href="chat()"
                                :aria-describedby="titleId(prescription)"
                                data-test="tell-doctor-chat"
                            >
                                <MessageCircle />
                                Написать врачу
                            </Link>
                        </Button>
                    </div>
                </template>
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
                        Больше не принимаю
                    </Button>
                </template>
            </PrescriptionDetails>
        </section>

        <StopMedicationDialog v-model:prescription="stopping" />
    </div>
</template>
