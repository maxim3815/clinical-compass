<script setup lang="ts">
import { ChevronDown, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import SafetyFindingCard from '@/components/SafetyFindingCard.vue';
import { Button } from '@/components/ui/button';
import type { SafetyRuleView } from '@/types';

type Caution = SafetyRuleView & {
    evidence: string[];
    // У какого препарата находка, если не у этого.
    target?: string;
};

const props = defineProps<{
    findings: (Caution & { key: string | number })[];
}>();

// Находки «с осторожностью» одной плашкой. Одна видна целиком, несколько свёрнуты до двух
// строк каждая: основание и источник — по «Подробнее».
const open = ref(props.findings.length === 1);
</script>

<template>
    <div
        class="flex flex-col gap-2 rounded-lg border border-warning-border bg-warning-subtle p-3 text-sm"
        data-test="safety-cautions"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1"
        >
            <p class="flex items-center gap-2 font-semibold text-warning">
                <TriangleAlert class="size-4 shrink-0" />
                <span class="whitespace-nowrap">С осторожностью</span>
                <span
                    v-if="findings.length > 1"
                    class="font-normal whitespace-nowrap text-muted-foreground"
                    >· {{ findings.length }}</span
                >
            </p>
            <Button
                v-if="findings.length > 1"
                type="button"
                variant="ghost"
                size="sm"
                class="-my-1"
                :aria-expanded="open"
                data-test="safety-cautions-toggle"
                @click="open = !open"
            >
                {{ open ? 'Свернуть' : 'Подробнее' }}
                <span class="sr-only">о находках «с осторожностью»</span>
                <ChevronDown
                    class="transition-transform"
                    :class="{ 'rotate-180': open }"
                />
            </Button>
        </div>
        <template v-if="open">
            <SafetyFindingCard
                v-for="(finding, index) in findings"
                :key="finding.key"
                :finding="finding"
                :target="finding.target"
                :class="{ 'border-t border-warning-border pt-2': index > 0 }"
                plain
            />
        </template>
        <ul v-else class="flex min-w-0 flex-col gap-1">
            <li
                v-for="finding in findings"
                :key="finding.key"
                class="min-w-0 break-words"
                :data-level="finding.level"
                data-test="safety-finding"
            >
                <span class="line-clamp-2"
                    ><span v-if="finding.target" class="font-medium"
                        >{{ finding.target }}: </span
                    >{{ finding.text }}</span
                >
                <span
                    v-if="finding.evidence.length > 0"
                    class="block truncate text-muted-foreground"
                    >{{ finding.evidence[0] }}</span
                >
            </li>
        </ul>
    </div>
</template>
