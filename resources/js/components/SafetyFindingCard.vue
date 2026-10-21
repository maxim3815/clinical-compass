<script setup lang="ts">
import { ExternalLink, OctagonAlert, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import type { SafetyRuleView } from '@/types';

const props = defineProps<{
    finding: SafetyRuleView & { evidence: string[] };
    // У какого препарата находка, если это не очевидно из места.
    target?: string;
}>();

const contraindicated = computed(
    () => props.finding.level === 'contraindicated',
);
</script>

<template>
    <div
        class="flex flex-col gap-2 rounded-md border p-3 text-sm"
        :class="
            contraindicated
                ? 'border-destructive/40 bg-destructive/5'
                : 'border-amber-300 bg-amber-50'
        "
        :data-level="finding.level"
        data-test="safety-finding"
    >
        <p
            class="flex items-start gap-2 font-medium"
            :class="contraindicated ? 'text-destructive' : 'text-amber-900'"
        >
            <OctagonAlert
                v-if="contraindicated"
                class="mt-0.5 size-4 shrink-0"
            />
            <TriangleAlert v-else class="mt-0.5 size-4 shrink-0" />
            <span
                >{{ finding.level_label
                }}{{ target ? ` · ${target}` : '' }}</span
            >
        </p>

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
            <summary class="cursor-pointer">
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
                class="mt-2 inline-flex items-center gap-1 underline underline-offset-4"
            >
                Открыть источник
                <ExternalLink class="size-3" />
            </a>
        </details>

        <slot />
    </div>
</template>
