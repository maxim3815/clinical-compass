<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { Prescription } from '@/types';

const props = defineProps<{
    prescription: Prescription;
    // Врачу у действующего назначения показываем, что пациент ещё не выбрал время приёма.
    // У врача и текст плотнее, у пациента — не мельче 16 px.
    forDoctor?: boolean;
}>();

const isCurrent = computed(
    () =>
        props.prescription.status === 'active' ||
        props.prescription.status === 'upcoming',
);

const period = computed(() =>
    props.prescription.ends_on === null
        ? `С ${props.prescription.starts_on}`
        : `С ${props.prescription.starts_on} по ${props.prescription.ends_on}`,
);

// Чем закончилась версия: для истории назначений.
const closing = computed(() => {
    const { status, closed_at, cancel_reason } = props.prescription;

    switch (status) {
        case 'changed':
            return `Изменено ${closed_at}`;
        case 'cancelled':
            return cancel_reason === null
                ? `Отменено ${closed_at}`
                : `Отменено ${closed_at}: ${cancel_reason}`;
        case 'completed':
            return 'Завершено';
        default:
            return null;
    }
});
</script>

<template>
    <article
        class="flex flex-col break-words"
        :class="
            forDoctor
                ? 'gap-3 rounded-lg border p-4 text-sm'
                : 'gap-3 rounded-2xl border bg-card p-4 text-base shadow-xs'
        "
        data-test="prescription"
    >
        <header class="flex flex-wrap items-center gap-2">
            <!-- По id названия кнопки карточки озвучиваются вместе с препаратом. -->
            <h3
                :id="`prescription-${prescription.id}-title`"
                :class="
                    forDoctor
                        ? 'text-base font-medium'
                        : 'text-lg font-semibold'
                "
            >
                {{ prescription.drug.name
                }}<template v-if="prescription.strength"
                    >, {{ prescription.strength }}</template
                >
            </h3>
            <Badge variant="secondary" :class="{ 'text-base': !forDoctor }">
                {{ prescription.kind_label }}
            </Badge>
            <Badge
                v-if="prescription.status === 'upcoming'"
                variant="outline"
                :class="{ 'text-base': !forDoctor }"
            >
                Начнётся {{ prescription.starts_on }}
            </Badge>
        </header>

        <p v-if="prescription.kind === 'regular' && prescription.dose">
            <span class="font-medium">Доза:</span> {{ prescription.dose }}
        </p>

        <ul v-if="prescription.kind === 'regular'" class="flex flex-col gap-1">
            <li
                v-for="intake in prescription.intakes"
                :key="intake.time_of_day"
                data-test="prescription-intake"
            >
                <span class="font-medium"
                    >{{ intake.time_of_day
                    }}<template v-if="intake.time">, {{ intake.time }}</template
                    ><template v-if="intake.dose">:</template></span
                >
                {{ intake.dose }}
                <span
                    v-if="forDoctor && isCurrent && intake.time === null"
                    class="text-muted-foreground"
                >
                    · пациент ещё не выбрал время
                </span>
            </li>
        </ul>

        <div v-else-if="prescription.as_needed" class="flex flex-col gap-1">
            <p>
                <span class="font-medium">За приём:</span>
                {{ prescription.as_needed.dose }}
            </p>
            <p v-if="prescription.as_needed.limit">
                {{ prescription.as_needed.limit }}
            </p>
        </div>

        <p
            v-if="prescription.instruction"
            :class="{ 'rounded-xl bg-muted p-3': !forDoctor }"
        >
            {{ prescription.instruction }}
        </p>

        <p class="text-muted-foreground">
            {{ period }}
            <template v-if="prescription.prescriber">
                · назначил врач {{ prescription.prescriber }}
            </template>
            <template v-if="prescription.prescribed_by">
                · кто назначил: {{ prescription.prescribed_by }}
            </template>
        </p>

        <div v-if="closing" data-test="prescription-closing">
            <p class="font-medium">{{ closing }}</p>
            <p v-if="prescription.cancel_comment" class="text-muted-foreground">
                {{ prescription.cancel_comment }}
            </p>
        </div>

        <slot name="safety" />

        <slot name="notice" />

        <div v-if="$slots.actions" class="flex flex-wrap gap-2">
            <slot name="actions" />
        </div>
    </article>
</template>
