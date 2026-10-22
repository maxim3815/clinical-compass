<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import SafetyCautionGroup from '@/components/SafetyCautionGroup.vue';
import SafetyFindingCard from '@/components/SafetyFindingCard.vue';
import SafetyUnchecked from '@/components/SafetyUnchecked.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { SafetyReport, SafetyResult } from '@/types';

const props = defineProps<{
    report: SafetyReport | null;
    loading: boolean;
    // Запрос проверки не прошёл: показываем ошибку и «Повторить», назначить пока нельзя.
    failed?: boolean;
    // Какой препарат проверяется.
    drug: string | null;
    // Профиль пациента: внести недостающие данные, не теряя форму (в новой вкладке).
    profileUrl?: string;
}>();

const emit = defineEmits<{
    retry: [];
}>();

// Сначала противопоказания: из-за них нужно обоснование, оно идёт сразу под находками.
const byLevel = (findings: SafetyResult[]) =>
    [...findings].sort(
        (a, b) =>
            Number(b.level === 'contraindicated') -
            Number(a.level === 'contraindicated'),
    );

const findings = computed(() => byLevel(props.report?.findings ?? []));
const caused = computed(() => byLevel(props.report?.caused ?? []));

// Противопоказания — каждое отдельно, «с осторожностью» — одной плашкой.
const contraindications = (items: SafetyResult[]) =>
    items.filter((item) => item.level === 'contraindicated');
const cautions = (items: SafetyResult[]) =>
    items.filter((item) => item.level === 'caution');
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
        :class="{ 'border-danger-border': report?.requires_justification }"
        aria-labelledby="safety-check-title"
        data-test="safety-check"
    >
        <h2
            id="safety-check-title"
            class="flex items-center gap-2 font-semibold"
        >
            <ShieldCheck class="size-5 shrink-0 text-primary" />
            <span
                >Проверка безопасности<template v-if="drug"
                    >: {{ drug }}</template
                ></span
            >
            <Spinner v-if="loading" class="size-4" />
        </h2>

        <!-- Результат озвучивается, когда приходит: врач может не смотреть на этот блок. -->
        <div class="flex flex-col gap-3" aria-live="polite">
            <template v-if="report">
                <p
                    v-if="!report.has_rules"
                    class="text-sm text-muted-foreground"
                >
                    Для этого препарата правил проверки нет.
                </p>

                <template v-else>
                    <p
                        v-if="report.findings.length === 0"
                        class="flex items-center gap-1.5 text-sm"
                        data-test="safety-clear"
                    >
                        <ShieldCheck
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        {{
                            caused.length > 0
                                ? 'У самого препарата по внесённым данным противопоказаний нет.'
                                : 'По внесённым данным противопоказаний нет.'
                        }}
                    </p>

                    <SafetyFindingCard
                        v-for="finding in contraindications(findings)"
                        :key="finding.key"
                        :finding="finding"
                        :decided="finding.decided"
                    >
                        <p v-if="finding.decided" class="text-muted-foreground">
                            Решение по этой находке уже принято, обоснование не
                            нужно.
                        </p>
                    </SafetyFindingCard>

                    <SafetyCautionGroup
                        v-if="cautions(findings).length > 0"
                        :findings="cautions(findings)"
                    />
                </template>

                <template v-if="caused.length > 0">
                    <p class="text-sm font-medium">
                        Появится у других препаратов пациента:
                    </p>
                    <SafetyFindingCard
                        v-for="finding in contraindications(caused)"
                        :key="finding.key"
                        :finding="finding"
                        :target="finding.target"
                        :decided="finding.decided"
                    />
                    <SafetyCautionGroup
                        v-if="cautions(caused).length > 0"
                        :findings="cautions(caused)"
                    />
                </template>
            </template>

            <div
                v-else-if="failed"
                role="alert"
                class="flex flex-col items-start gap-2 rounded-lg border border-danger-border bg-danger-subtle p-3 text-sm text-danger"
                data-test="safety-check-failed"
            >
                <p class="font-medium">
                    Не удалось проверить безопасность. Назначить можно после
                    проверки.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="emit('retry')"
                >
                    Повторить
                </Button>
            </div>

            <p v-else-if="loading" class="text-sm text-muted-foreground">
                Проверяем по данным пациента…
            </p>

            <p v-else class="text-sm text-muted-foreground">
                Выберите препарат: здесь появится проверка по данным пациента.
            </p>
        </div>

        <slot />

        <SafetyUnchecked
            v-if="report"
            :unchecked="report.unchecked"
            :unknown-drugs="report.unknown_drugs"
            :profile-href="profileUrl"
            new-tab
            open
        />
    </section>
</template>
