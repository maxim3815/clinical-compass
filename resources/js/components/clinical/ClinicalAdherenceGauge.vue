<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    percentage: number;
    target?: number;
    size?: number;
    strokeWidth?: number;
    title?: string;
}

const props = withDefaults(defineProps<Props>(), {
    target: 80,
    size: 140,
    strokeWidth: 12,
    title: '7-Day Adherence Rate',
});

const radius = computed(() => (props.size - props.strokeWidth) / 2);
const circumference = computed(() => 2 * Math.PI * radius.value);
const strokeDashoffset = computed(() => {
    const p = Math.min(100, Math.max(0, props.percentage));
    return circumference.value - (p / 100) * circumference.value;
});

const isBelowTarget = computed(() => props.percentage < props.target);
const strokeColor = computed(() =>
    isBelowTarget.value ? '#F59E0B' : '#235347',
);
</script>

<template>
    <div
        class="flex flex-col items-center rounded-2xl border border-[#E2E8F0] bg-white p-5 text-center font-sans shadow-sm"
    >
        <span v-if="title" class="mb-3 text-xs font-semibold text-[#64748B]">
            {{ title }}
        </span>

        <div class="relative flex items-center justify-center">
            <svg :width="size" :height="size" class="-rotate-90 transform">
                <!-- Background Circle -->
                <circle
                    :cx="size / 2"
                    :cy="size / 2"
                    :r="radius"
                    class="text-[#F1F5F9]"
                    stroke="currentColor"
                    :stroke-width="strokeWidth"
                    fill="transparent"
                />
                <!-- Progress Circle -->
                <circle
                    :cx="size / 2"
                    :cy="size / 2"
                    :r="radius"
                    :stroke="strokeColor"
                    :stroke-width="strokeWidth"
                    stroke-linecap="round"
                    fill="transparent"
                    :stroke-dasharray="circumference"
                    :stroke-dashoffset="strokeDashoffset"
                    class="transition-all duration-700 ease-out"
                />
            </svg>

            <div class="absolute flex flex-col items-center justify-center">
                <span class="text-2xl font-bold text-[#1E293B]">
                    {{ percentage }}%
                </span>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-1.5 text-xs font-medium">
            <span
                v-if="isBelowTarget"
                class="inline-flex items-center gap-1 rounded-full bg-[#FEF3C7] px-2.5 py-0.5 font-semibold text-[#B45309]"
            >
                ⚠️ Below target
            </span>
            <span
                v-else
                class="inline-flex items-center gap-1 rounded-full bg-[#D1E7DD] px-2.5 py-0.5 font-semibold text-[#0F5132]"
            >
                ✓ On target
            </span>
        </div>
        <span class="mt-1 text-[11px] text-[#94A3B8]"
            >Target ≥ {{ target }}%</span
        >
    </div>
</template>
