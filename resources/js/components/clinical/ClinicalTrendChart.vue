<script setup lang="ts">
import { computed } from 'vue';

interface DataPoint {
    label: string;
    score: number;
}

interface Props {
    title?: string;
    data?: DataPoint[];
    remissionThreshold?: number;
    maxScore?: number;
}

const props = withDefaults(defineProps<Props>(), {
    title: 'Symptom Reduction Trend — GAD-7 Longitudinal Score',
    remissionThreshold: 7,
    maxScore: 21,
    data: () => [
        { label: 'Week 0', score: 18 },
        { label: 'Week 2', score: 14 },
        { label: 'Week 4', score: 9 },
        { label: 'Week 8', score: 5 },
    ],
});

// SVG dimensions
const width = 500;
const height = 180;
const paddingX = 40;
const paddingY = 30;

const points = computed(() => {
    if (!props.data.length) return [];
    const stepX = (width - paddingX * 2) / (props.data.length - 1);

    return props.data.map((d, index) => {
        const x = paddingX + index * stepX;
        const normalizedY = 1 - d.score / props.maxScore;
        const y = paddingY + normalizedY * (height - paddingY * 2);
        return { ...d, x, y };
    });
});

const polylinePoints = computed(() => {
    return points.value.map((p) => `${p.x},${p.y}`).join(' ');
});

const areaPoints = computed(() => {
    if (!points.value.length) return '';
    const first = points.value[0];
    const last = points.value[points.value.length - 1];
    const bottom = height - paddingY;
    return `${first.x},${bottom} ${polylinePoints.value} ${last.x},${bottom}`;
});

const thresholdY = computed(() => {
    const normalizedY = 1 - props.remissionThreshold / props.maxScore;
    return paddingY + normalizedY * (height - paddingY * 2);
});
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-3xl border border-[#E2E8F0] bg-white p-6 font-sans shadow-xs"
    >
        <div class="flex items-center justify-between">
            <h4 class="text-sm font-bold text-[#1E293B]">{{ title }}</h4>
            <span
                class="rounded-full bg-[#D1E7DD] px-2.5 py-0.5 text-xs font-semibold text-[#0F5132]"
            >
                Динамика терапии
            </span>
        </div>

        <div class="relative w-full overflow-hidden">
            <svg :viewBox="`0 0 ${width} ${height}`" class="h-auto w-full">
                <!-- Remission Line -->
                <line
                    :x1="paddingX"
                    :y1="thresholdY"
                    :x2="width - paddingX"
                    :y2="thresholdY"
                    stroke="#64748B"
                    stroke-dasharray="4 4"
                    stroke-width="1.5"
                />
                <text
                    :x="width - paddingX"
                    :y="thresholdY - 6"
                    text-anchor="end"
                    class="fill-[#64748B] text-[11px] font-medium"
                >
                    Clinical Remission (≤ {{ remissionThreshold }})
                </text>

                <!-- Gradient area under curve -->
                <polygon
                    :points="areaPoints"
                    fill="url(#greenGradient)"
                    opacity="0.15"
                />

                <defs>
                    <linearGradient
                        id="greenGradient"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop offset="0%" stop-color="#235347" />
                        <stop
                            offset="100%"
                            stop-color="#E8F2EC"
                            stop-opacity="0"
                        />
                    </linearGradient>
                </defs>

                <!-- Trend Line -->
                <polyline
                    :points="polylinePoints"
                    fill="none"
                    stroke="#235347"
                    stroke-width="3"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />

                <!-- Point Circles and Labels -->
                <g v-for="p in points" :key="p.label">
                    <circle
                        :cx="p.x"
                        :cy="p.y"
                        r="5"
                        fill="#235347"
                        stroke="#FFFFFF"
                        stroke-width="2"
                    />
                    <text
                        :x="p.x"
                        :y="p.y - 10"
                        text-anchor="middle"
                        class="fill-[#1E293B] text-[11px] font-bold"
                    >
                        {{ p.score }}
                    </text>
                    <text
                        :x="p.x"
                        :y="height - 8"
                        text-anchor="middle"
                        class="fill-[#64748B] text-[10px]"
                    >
                        {{ p.label }}
                    </text>
                </g>
            </svg>
        </div>
    </div>
</template>
