<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

// Временный знак приложения: компас, как на иконке приложения (public/favicon.svg).
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
        viewBox="70 70 372 372"
        :class="className"
        v-bind="$attrs"
    >
        <circle
            cx="256"
            cy="256"
            r="172"
            fill="none"
            stroke="currentColor"
            stroke-width="28"
        />
        <path d="M356 156 280 280 232 232Z" fill="currentColor" />
        <path
            d="M156 356 232 232 280 280Z"
            fill="currentColor"
            fill-opacity="0.5"
        />
    </svg>
</template>
