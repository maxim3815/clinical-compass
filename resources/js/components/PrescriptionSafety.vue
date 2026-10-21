<script setup lang="ts">
import SafetyFindingCard from '@/components/SafetyFindingCard.vue';
import SafetyUnchecked from '@/components/SafetyUnchecked.vue';
import { Button } from '@/components/ui/button';
import type { SafetyCard, SafetyFinding } from '@/types';

defineProps<{
    card: SafetyCard;
}>();

const emit = defineEmits<{
    continue: [finding: SafetyFinding];
}>();
</script>

<template>
    <div class="flex flex-col gap-2" data-test="prescription-safety">
        <SafetyFindingCard
            v-for="finding in card.findings"
            :key="finding.id"
            :finding="finding"
        >
            <div
                v-if="finding.decision"
                class="flex flex-col gap-0.5 border-t pt-2"
                data-test="safety-decision"
            >
                <p class="font-medium">{{ finding.decision.kind_label }}</p>
                <p>{{ finding.decision.text }}</p>
                <p class="text-muted-foreground">
                    {{ finding.decision.by }}, {{ finding.decision.at }}
                </p>
            </div>
            <div
                v-else-if="finding.awaits_decision"
                class="flex flex-wrap gap-2"
            >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="continue-finding-button"
                    @click="emit('continue', finding)"
                >
                    Продолжить приём
                </Button>
            </div>
        </SafetyFindingCard>

        <SafetyUnchecked
            :unchecked="card.unchecked"
            :unknown-drugs="card.unknown_drugs"
        />
    </div>
</template>
