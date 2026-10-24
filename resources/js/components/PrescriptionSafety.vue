<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowUp,
    MessageCircle,
    OctagonAlert,
    Pencil,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import SafetyCautionGroup from '@/components/SafetyCautionGroup.vue';
import SafetyFindingCard from '@/components/SafetyFindingCard.vue';
import SafetyUnchecked from '@/components/SafetyUnchecked.vue';
import { Button } from '@/components/ui/button';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { SafetyCard, SafetyFinding } from '@/types';

const props = defineProps<{
    card: SafetyCard;
    // Назначение врача можно изменить или отменить. Свой препарат пациента — только продолжить.
    changeable: boolean;
    profileHref: InertiaLinkProps['href'];
    chatHref: InertiaLinkProps['href'];
    // Изменить назначение врача: решение по находке прямо из плашки.
    editHref?: InertiaLinkProps['href'];
    // Вторые находки пары препаратов: решение одно, оно в плашке у первого препарата.
    pairedIds?: number[];
}>();

const emit = defineEmits<{
    continue: [finding: SafetyFinding];
    // Отменить назначение врача.
    cancel: [];
}>();

// Сначала то, что ждёт решения врача, затем решённое и «с осторожностью» одной плашкой.
const awaiting = computed(() =>
    props.card.findings.filter(
        (finding) =>
            finding.awaits_decision && !props.pairedIds?.includes(finding.id),
    ),
);
const paired = computed(() =>
    props.card.findings.filter(
        (finding) =>
            finding.awaits_decision && props.pairedIds?.includes(finding.id),
    ),
);
const decided = computed(() =>
    props.card.findings.filter(
        (finding) =>
            finding.level === 'contraindicated' && !finding.awaits_decision,
    ),
);
const cautions = computed(() =>
    props.card.findings.filter((finding) => finding.level === 'caution'),
);
</script>

<template>
    <div class="flex flex-col gap-2" data-test="prescription-safety">
        <SafetyFindingCard
            v-for="finding in awaiting"
            :key="finding.id"
            :finding="finding"
        >
            <div
                class="mt-1 flex flex-col gap-2 border-t border-danger-border pt-3"
            >
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test="continue-finding-button"
                        @click="emit('continue', finding)"
                    >
                        Продолжить приём
                    </Button>
                    <template v-if="changeable">
                        <Button
                            v-if="editHref"
                            variant="ghost"
                            size="sm"
                            as-child
                        >
                            <Link :href="editHref"><Pencil />Изменить</Link>
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="emit('cancel')"
                        >
                            Отменить назначение
                        </Button>
                    </template>
                    <Button v-else variant="ghost" size="sm" as-child>
                        <Link :href="chatHref">
                            <MessageCircle />
                            Написать пациенту
                        </Link>
                    </Button>
                </div>
                <p
                    v-if="!changeable || finding.related.length > 0"
                    class="text-muted-foreground"
                >
                    <template v-if="!changeable"
                        >Препарат добавил пациент: изменить или прекратить его
                        может только он.
                    </template>
                    <template v-if="finding.related.length > 0">
                        {{
                            `Решение закроет противопоказание и у препарата ${finding.related.join(', ')}.`
                        }}
                    </template>
                </p>
            </div>
        </SafetyFindingCard>

        <!-- Та же пара препаратов: полная плашка и решение — у первого из них. -->
        <a
            v-for="finding in paired"
            :key="finding.id"
            :href="`#prescription-${finding.related_ids[0]}-title`"
            class="flex items-start gap-2 rounded-lg border border-danger-border bg-danger-subtle p-3 text-sm font-medium text-danger hover:underline"
            :data-level="finding.level"
            data-test="safety-finding-paired"
        >
            <OctagonAlert class="mt-0.5 size-4 shrink-0" />
            <span
                >{{
                    `Противопоказано вместе с препаратом ${finding.related.join(', ')}: решение общее, оно выше`
                }}
                <ArrowUp class="inline size-4 align-text-bottom"
            /></span>
        </a>

        <SafetyFindingCard
            v-for="finding in decided"
            :key="finding.id"
            :finding="finding"
            decided
        >
            <div
                v-if="finding.decision"
                class="mt-1 flex flex-col gap-0.5 border-t pt-2"
                data-test="safety-decision"
            >
                <p class="font-medium">{{ finding.decision.kind_label }}</p>
                <p>{{ finding.decision.text }}</p>
                <p class="text-muted-foreground">
                    {{ finding.decision.by }}, {{ finding.decision.at }}
                </p>
            </div>
        </SafetyFindingCard>

        <SafetyCautionGroup
            v-if="cautions.length > 0"
            :findings="
                cautions.map((finding) => ({ ...finding, key: finding.id }))
            "
        />

        <p
            v-if="card.findings.length === 0"
            class="flex items-center gap-1.5 text-sm text-muted-foreground"
            data-test="safety-clear"
        >
            <ShieldCheck class="size-4 shrink-0" />
            По внесённым данным противопоказаний нет.
        </p>

        <SafetyUnchecked
            :unchecked="card.unchecked"
            :unknown-drugs="card.unknown_drugs"
            :profile-href="profileHref"
        />
    </div>
</template>
