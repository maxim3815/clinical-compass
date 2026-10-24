<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { Prescription } from '@/types';

/**
 * Текущие препараты пациента только для чтения: у терапевта и на странице направления.
 */
defineProps<{
    medications: Prescription[];
}>();

// Одной строкой: текст и <template v-if> подряд на сервере сливаются в один узел.
function schedule(medication: Prescription): string {
    if (medication.as_needed) {
        return [
            `по необходимости, ${medication.as_needed.dose}`,
            medication.as_needed.limit,
        ]
            .filter(Boolean)
            .join(', ');
    }

    const intakes = medication.intakes.map((intake) =>
        [intake.time_of_day, intake.dose].filter(Boolean).join(' '),
    );

    return [medication.dose, intakes.join(', ')].filter(Boolean).join(' · ');
}
</script>

<template>
    <p v-if="medications.length === 0" class="text-sm text-muted-foreground">
        Препаратов нет.
    </p>
    <ul v-else class="flex flex-col gap-2 text-sm" data-test="medications">
        <li v-for="medication in medications" :key="medication.id">
            <p class="font-medium">
                {{
                    [medication.drug.name, medication.strength]
                        .filter(Boolean)
                        .join(' ')
                }}
            </p>
            <p class="flex flex-wrap items-center gap-2 text-muted-foreground">
                <span>{{ schedule(medication) }}</span>
                <Badge
                    v-if="medication.source === 'patient'"
                    variant="secondary"
                    >Добавил пациент</Badge
                >
            </p>
        </li>
    </ul>
</template>
