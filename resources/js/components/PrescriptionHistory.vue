<script setup lang="ts">
import type { Prescription } from '@/types';

defineProps<{
    prescriptions: Prescription[];
}>();

// Как принимал: «Утром: 1 таблетка, 20 мг; Вечером: …» или доза «по необходимости».
function regimen(prescription: Prescription): string {
    if (prescription.kind === 'as_needed') {
        return prescription.as_needed === null
            ? ''
            : [prescription.as_needed.dose, prescription.as_needed.limit]
                  .filter(Boolean)
                  .join('. ');
    }

    return prescription.intakes
        .map((intake) =>
            intake.dose
                ? `${intake.time_of_day}: ${intake.dose}`
                : intake.time_of_day,
        )
        .join('; ');
}

function period(prescription: Prescription): string {
    return prescription.ends_on === null
        ? `с ${prescription.starts_on}`
        : `с ${prescription.starts_on} по ${prescription.ends_on}`;
}

// Чем закончилась версия.
function closing(prescription: Prescription): string | null {
    const { status, closed_at, cancel_reason } = prescription;

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
}
</script>

<template>
    <ul class="divide-y rounded-xl border bg-card text-sm shadow-xs">
        <li
            v-for="prescription in prescriptions"
            :key="prescription.id"
            class="flex flex-col gap-0.5 p-4 break-words"
            data-test="prescription-history-item"
        >
            <p>
                <span class="font-medium"
                    >{{ prescription.drug.name
                    }}<template v-if="prescription.strength"
                        >, {{ prescription.strength }}</template
                    ></span
                >
                <span class="text-muted-foreground">
                    · {{ prescription.kind_label.toLowerCase() }} ·
                    {{ period(prescription) }}</span
                >
            </p>
            <p v-if="regimen(prescription)" class="text-muted-foreground">
                {{ regimen(prescription) }}
            </p>
            <p
                v-if="closing(prescription)"
                class="font-medium"
                data-test="prescription-closing"
            >
                {{ closing(prescription) }}
            </p>
            <p v-if="prescription.cancel_comment" class="text-muted-foreground">
                {{ prescription.cancel_comment }}
            </p>
        </li>
    </ul>
</template>
