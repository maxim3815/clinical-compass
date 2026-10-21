<script setup lang="ts">
import { ExternalLink, OctagonAlert, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import type { SafetyRuleView } from '@/types';

const props = defineProps<{
    finding: SafetyRuleView & { evidence: string[] };
    // У какого препарата находка, если это не очевидно из места.
    target?: string;
    // По находке врач уже решил: подложка спокойная, уровень остаётся в тексте и иконке.
    decided?: boolean;
    // Находка внутри общей плашки своего уровня: без своей рамки и заголовка уровня.
    plain?: boolean;
}>();

const contraindicated = computed(
    () => props.finding.level === 'contraindicated',
);

const box = computed(() => {
    if (props.plain) {
        return '';
    }

    if (props.decided) {
        return 'rounded-lg border bg-muted/60 p-3';
    }

    return contraindicated.value
        ? 'rounded-lg border border-danger-border bg-danger-subtle p-3'
        : 'rounded-lg border border-warning-border bg-warning-subtle p-3';
});
</script>

<template>
    <div
        class="flex flex-col gap-1.5 text-sm"
        :class="box"
        :data-level="finding.level"
        data-test="safety-finding"
    >
        <p
            v-if="!plain"
            class="flex items-start gap-2 font-semibold"
            :class="contraindicated ? 'text-danger' : 'text-warning'"
        >
            <OctagonAlert
                v-if="contraindicated"
                class="mt-0.5 size-4 shrink-0"
            />
            <TriangleAlert v-else class="mt-0.5 size-4 shrink-0" />
            <span
                >{{ finding.level_label
                }}<span v-if="target" class="font-medium text-foreground">
                    · {{ target }}</span
                ><span v-if="decided" class="font-normal text-muted-foreground">
                    · решение принято</span
                ></span
            >
        </p>
        <p v-else-if="target" class="font-medium">{{ target }}</p>

        <p>{{ finding.text }}</p>

        <ul
            v-if="finding.evidence.length > 0"
            class="flex flex-col gap-0.5 text-muted-foreground"
            data-test="safety-evidence"
        >
            <li v-for="line in finding.evidence" :key="line">{{ line }}</li>
        </ul>

        <p v-if="finding.advice">{{ finding.advice }}</p>

        <details class="text-muted-foreground" data-test="safety-source">
            <summary
                class="w-fit cursor-pointer rounded-sm hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid pointer-coarse:py-2.5"
            >
                Источник: {{ finding.source.document }},
                {{ finding.source.section }}
            </summary>
            <blockquote
                v-if="finding.source.quote"
                class="mt-2 border-l-2 pl-3 italic"
            >
                {{ finding.source.quote }}
            </blockquote>
            <a
                :href="finding.source.url"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-2 inline-flex items-center gap-1 underline underline-offset-4 hover:text-foreground"
            >
                Открыть источник
                <ExternalLink class="size-3.5" />
                <span class="sr-only">(в новой вкладке)</span>
            </a>
        </details>

        <slot />
    </div>
</template>
