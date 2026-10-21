<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

// Знак приложения — компас: кольцо, засечки сторон света и стрелка. Тот же рисунок
// в иконке приложения (public/favicon.svg). Цвет берётся из currentColor.
defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="64 64 384 384"
        aria-hidden="true"
        :class="className"
        v-bind="$attrs"
    >
        <circle
            cx="256"
            cy="256"
            r="172"
            fill="none"
            stroke="currentColor"
            stroke-width="32"
        />
        <path
            d="M256 100v40M256 372v40M100 256h40M372 256h40"
            stroke="currentColor"
            stroke-width="24"
            stroke-linecap="round"
        />
        <path d="M339 173 232 232 280 280Z" fill="currentColor" />
        <path
            d="M173 339 232 232 280 280Z"
            fill="currentColor"
            fill-opacity="0.45"
        />
    </svg>
</template>
