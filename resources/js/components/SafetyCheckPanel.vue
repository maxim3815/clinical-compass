<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';
import SafetyFindingCard from '@/components/SafetyFindingCard.vue';
import SafetyUnchecked from '@/components/SafetyUnchecked.vue';
import { Spinner } from '@/components/ui/spinner';
import type { SafetyReport } from '@/types';

defineProps<{
    report: SafetyReport | null;
    loading: boolean;
}>();
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-lg border p-4"
        aria-live="polite"
        data-test="safety-check"
    >
        <h3 class="flex items-center gap-2 text-sm font-medium">
            <ShieldCheck class="size-4" />
            Проверка безопасности
            <Spinner v-if="loading" class="size-4" />
        </h3>

        <template v-if="report">
            <p v-if="!report.has_rules" class="text-sm text-muted-foreground">
                Для этого препарата правил проверки нет.
            </p>

            <template v-else>
                <p
                    v-if="report.findings.length === 0"
                    class="text-sm"
                    data-test="safety-clear"
                >
                    По внесённым данным противопоказаний нет.
                </p>

                <SafetyFindingCard
                    v-for="finding in report.findings"
                    :key="finding.key"
                    :finding="finding"
                >
                    <p v-if="finding.decided" class="text-muted-foreground">
                        Решение по этой находке уже принято, обоснование не
                        нужно.
                    </p>
                </SafetyFindingCard>
            </template>

            <template v-if="report.caused.length > 0">
                <p class="text-sm font-medium">
                    Появится у других препаратов пациента:
                </p>
                <SafetyFindingCard
                    v-for="finding in report.caused"
                    :key="finding.key"
                    :finding="finding"
                    :target="finding.target"
                />
            </template>

            <SafetyUnchecked
                :unchecked="report.unchecked"
                :unknown-drugs="report.unknown_drugs"
            />
        </template>

        <p v-else-if="!loading" class="text-sm text-muted-foreground">
            Выберите препарат, чтобы проверить назначение.
        </p>
    </section>
</template>
