<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { OctagonAlert, TriangleAlert, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import AdherenceSignal from '@/components/AdherenceSignal.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CHIP_ICON, DANGER_CHIP } from '@/lib/chips';
import { formatAge } from '@/lib/plural';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { ChatPatientContext } from '@/types';

const props = defineProps<{
    context: ChatPatientContext;
    cardHref: InertiaLinkProps['href'];
}>();

const summary = computed(() =>
    [
        props.context.sex === null
            ? null
            : props.context.sex === 'female'
              ? 'Женщина'
              : 'Мужчина',
        props.context.age === null ? null : formatAge(props.context.age),
    ]
        .filter(Boolean)
        .join(' · '),
);

// Как принимает, одной строкой: «утром 1 таблетка, 20 мг; вечером …».
function regimen(prescription: ChatPatientContext['prescriptions'][number]) {
    if (prescription.kind === 'as_needed') {
        return prescription.as_needed?.dose ?? '';
    }

    return prescription.intakes
        .map((intake) =>
            [intake.time_of_day.toLowerCase(), intake.dose ?? prescription.dose]
                .filter(Boolean)
                .join(' '),
        )
        .join('; ');
}
</script>

<template>
    <div
        class="flex flex-col gap-4 rounded-xl border bg-card p-4 text-sm shadow-xs"
        data-test="chat-patient-context"
    >
        <div class="flex flex-col gap-1">
            <h2 class="text-base font-semibold">О пациенте</h2>
            <p v-if="summary" class="text-muted-foreground">{{ summary }}</p>
            <p v-if="context.diagnosis">
                <span class="font-semibold">{{ context.diagnosis.code }}</span>
                {{ context.diagnosis.name }}
            </p>
            <p v-else class="text-muted-foreground">
                Основной диагноз не указан.
            </p>
        </div>

        <ul
            v-if="
                context.contraindications.length > 0 || context.adherence.raised
            "
            class="flex flex-col gap-2"
            aria-label="Требует внимания"
        >
            <li
                v-if="context.contraindications.length > 0"
                :class="DANGER_CHIP"
            >
                <OctagonAlert :class="CHIP_ICON" />
                <span
                    >Противопоказание без решения:
                    {{ context.contraindications.join(', ') }}</span
                >
            </li>
            <li
                v-if="context.adherence.raised"
                class="flex items-center gap-1.5"
            >
                <TriangleAlert class="size-4 shrink-0 text-warning" />
                <AdherenceSignal :signal="context.adherence" card />
            </li>
        </ul>

        <div class="flex flex-col gap-2">
            <h3 class="font-medium">Принимает сейчас</h3>
            <p
                v-if="context.prescriptions.length === 0"
                class="text-muted-foreground"
            >
                Назначений нет.
            </p>
            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="prescription in context.prescriptions"
                    :key="prescription.id"
                    class="flex flex-col gap-0.5"
                >
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-medium"
                            >{{ prescription.drug.name
                            }}<template v-if="prescription.strength"
                                >, {{ prescription.strength }}</template
                            ></span
                        >
                        <Badge
                            v-if="prescription.source === 'patient'"
                            variant="outline"
                            >Добавил пациент</Badge
                        >
                    </p>
                    <p class="text-muted-foreground">
                        {{
                            prescription.kind === 'as_needed'
                                ? `По необходимости: ${regimen(prescription)}`
                                : regimen(prescription)
                        }}
                    </p>
                </li>
            </ul>
        </div>

        <Button variant="outline" size="sm" class="self-start" as-child>
            <Link :href="cardHref">
                <UserRound />
                Карточка пациента
            </Link>
        </Button>
    </div>
</template>
