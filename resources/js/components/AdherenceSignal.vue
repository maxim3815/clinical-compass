<script setup lang="ts">
import { computed } from 'vue';
import type { AdherenceSignal } from '@/types';

const props = defineProps<{
    signal: AdherenceSignal;
    // В карточке пациента и в очереди «Требуют внимания» показываются только причины сигнала,
    // доля — с подписью.
    card?: boolean;
}>();

const share = computed(() => {
    const value = props.signal.share === null ? '—' : `${props.signal.share}%`;

    return props.card ? `Приём за 7 дней: ${value}` : value;
});

// Сигнал — повод поговорить с пациентом, а не опасность: предупреждающий цвет.
const highlight =
    'rounded-md border border-warning-border bg-warning-subtle px-1.5 py-0.5 font-medium text-warning';
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1.5">
        <span
            v-if="!card || signal.low_share"
            class="whitespace-nowrap tabular-nums"
            :class="{ [highlight]: signal.low_share }"
            :data-test="signal.low_share ? 'low-share' : undefined"
        >
            {{ share }}
        </span>
        <span
            v-if="signal.consecutive_misses !== null"
            class="whitespace-nowrap"
            :class="highlight"
            data-test="consecutive-misses"
        >
            Пропуски подряд: {{ signal.consecutive_misses }}
        </span>
    </span>
</template>
