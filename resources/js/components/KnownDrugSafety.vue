<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ChevronDown,
    CircleHelp,
    OctagonAlert,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import { index as drugs } from '@/routes/doctor/drugs';
import type { RelatedFinding } from '@/types';

/**
 * Проверка у препарата из списка препаратов от других врачей в карточке пациента. Своих правил
 * у него нет: сочетание — находка у препарата справочника, где он среди связанных. Здесь ссылки
 * на эти находки, строка о том, с чем препарат проверен, и почему он в списке — свёрнуто, чтобы
 * не уходить из карточки.
 */
defineProps<{
    code: string | null;
    reason: string | null;
    related: RelatedFinding[];
}>();

function text(finding: RelatedFinding): string {
    const where = finding.above ? 'выше' : 'ниже';

    if (finding.level === 'caution') {
        return `С осторожностью вместе с препаратом ${finding.targetName}: подробности в его карточке ${where}`;
    }

    return finding.awaitsDecision
        ? `Противопоказано вместе с препаратом ${finding.targetName}: решение в его карточке ${where}`
        : `Противопоказано вместе с препаратом ${finding.targetName}: решение принято, оно в его карточке ${where}`;
}

function tone(finding: RelatedFinding): string {
    if (finding.level === 'caution') {
        return 'border-warning-border bg-warning-subtle text-warning';
    }

    return finding.awaitsDecision
        ? 'border-danger-border bg-danger-subtle text-danger'
        : 'bg-muted/60 text-muted-foreground';
}
</script>

<template>
    <div class="flex flex-col gap-2" data-test="known-drug-safety">
        <a
            v-for="finding in related"
            :key="finding.id"
            :href="`#prescription-${finding.targetId}-title`"
            class="flex items-start gap-2 rounded-lg border p-3 text-sm font-medium hover:underline"
            :class="tone(finding)"
            :data-level="finding.level"
            data-test="known-drug-finding"
        >
            <OctagonAlert
                v-if="finding.level === 'contraindicated'"
                class="mt-0.5 size-4 shrink-0"
            />
            <TriangleAlert v-else class="mt-0.5 size-4 shrink-0" />
            <span
                >{{ text(finding) }}
                <ArrowUp
                    v-if="finding.above"
                    class="inline size-4 align-text-bottom" />
                <ArrowDown v-else class="inline size-4 align-text-bottom"
            /></span>
        </a>

        <p
            v-if="related.length === 0"
            class="flex items-center gap-1.5 text-sm text-muted-foreground"
            data-test="safety-clear"
        >
            <ShieldCheck class="size-4 shrink-0" />
            По внесённым данным противопоказаний с препаратами справочника нет.
        </p>

        <p class="flex gap-1.5 text-sm text-muted-foreground">
            <CircleHelp class="mt-0.5 size-4 shrink-0" />
            Сочетания проверены только с препаратами справочника.
        </p>

        <details
            v-if="reason"
            class="group text-sm"
            data-test="known-drug-reason"
        >
            <summary
                class="flex w-fit cursor-pointer list-none items-center gap-1.5 rounded-sm pl-5.5 text-primary hover:underline pointer-coarse:min-h-11 [&::-webkit-details-marker]:hidden"
            >
                Почему препарат в списке
                <ChevronDown
                    class="size-4 shrink-0 transition-transform group-open:rotate-180"
                />
            </summary>
            <p class="mt-1 pl-5.5">
                {{ reason }}
                <Link
                    v-if="code"
                    :href="`${drugs.url()}#known-${code}`"
                    class="text-primary underline underline-offset-4"
                    >Весь список</Link
                >
            </p>
        </details>
    </div>
</template>
