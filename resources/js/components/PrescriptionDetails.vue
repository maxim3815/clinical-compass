<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { Prescription } from '@/types';

const props = defineProps<{
    prescription: Prescription;
    // Врачу у действующего назначения показываем, что пациент ещё не выбрал время приёма.
    // У врача и текст плотнее, у пациента — не мельче 16 px.
    forDoctor?: boolean;
    // У назначения противопоказание, по которому врач ещё не решил: рамка карточки красная.
    attention?: boolean;
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
</script>

<template>
    <!-- У врача: карточка с действиями в шапке и проверкой безопасности ниже, отдельным блоком. -->
    <article
        v-if="forDoctor"
        class="flex flex-col rounded-xl border bg-card text-sm break-words shadow-xs"
        :class="{ 'border-danger-border': attention }"
        data-test="prescription"
    >
        <div class="flex flex-col gap-2 p-4">
            <header class="flex flex-wrap items-start justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <!-- По id названия кнопки карточки озвучиваются вместе с препаратом. -->
                    <h3
                        :id="`prescription-${prescription.id}-title`"
                        class="scroll-mt-24 text-base font-semibold"
                    >
                        {{ prescription.drug.name
                        }}<template v-if="prescription.strength"
                            >, {{ prescription.strength }}</template
                        >
                    </h3>
                    <Badge variant="secondary">
                        {{ prescription.kind_label }}
                    </Badge>
                    <Badge
                        v-if="prescription.status === 'upcoming'"
                        variant="outline"
                    >
                        Начнётся {{ prescription.starts_on }}
                    </Badge>
                </div>
                <div v-if="$slots.actions" class="-my-1 flex gap-1">
                    <slot name="actions" />
                </div>
            </header>

            <p v-if="prescription.kind === 'regular' && prescription.dose">
                <span class="font-medium">Доза:</span> {{ prescription.dose }}
            </p>

            <ul
                v-if="prescription.kind === 'regular'"
                class="flex flex-col gap-0.5"
            >
                <li
                    v-for="intake in prescription.intakes"
                    :key="intake.time_of_day"
                    data-test="prescription-intake"
                >
                    <span class="font-medium"
                        >{{ intake.time_of_day
                        }}<template v-if="intake.time"
                            >, {{ intake.time }}</template
                        ><template v-if="intake.dose">:</template></span
                    >
                    {{ intake.dose }}
                    <span
                        v-if="isCurrent && intake.time === null"
                        class="text-warning"
                    >
                        · пациент ещё не выбрал время
                    </span>
                </li>
            </ul>

            <p v-else-if="prescription.as_needed">
                <span class="font-medium">За приём:</span>
                {{ prescription.as_needed.dose
                }}<template v-if="prescription.as_needed.limit"
                    >. {{ prescription.as_needed.limit }}</template
                >
            </p>

            <p v-if="prescription.instruction" class="text-muted-foreground">
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
        </div>

        <div v-if="$slots.safety" class="border-t p-4">
            <slot name="safety" />
        </div>
    </article>

    <article
        v-else
        class="flex flex-col gap-3 rounded-2xl border bg-card p-4 text-base break-words shadow-xs"
        data-test="prescription"
    >
        <header class="flex flex-wrap items-center gap-2">
            <!-- По id названия кнопки карточки озвучиваются вместе с препаратом. -->
            <h3
                :id="`prescription-${prescription.id}-title`"
                class="text-lg font-semibold"
            >
                {{ prescription.drug.name
                }}<template v-if="prescription.strength"
                    >, {{ prescription.strength }}</template
                >
            </h3>
            <Badge variant="secondary" class="text-base">
                {{ prescription.kind_label }}
            </Badge>
            <Badge
                v-if="prescription.status === 'upcoming'"
                variant="outline"
                class="text-base"
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

        <p v-if="prescription.instruction" class="rounded-xl bg-muted p-3">
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

        <slot name="notice" />

        <div v-if="$slots.actions" class="flex flex-wrap gap-2">
            <slot name="actions" />
        </div>
    </article>
</template>
