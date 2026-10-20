<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    variant?:
        | 'taken'
        | 'missed'
        | 'skipped'
        | 'below-target'
        | 'referral-mandatory'
        | 'referral-recommended'
        | 'condition'
        | 'neutral';
    size?: 'sm' | 'md';
    removable?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'neutral',
    size: 'md',
    removable: false,
});

const emit = defineEmits<{
    (e: 'remove'): void;
}>();

const variantClasses = computed(() => {
    switch (props.variant) {
        case 'taken':
            return 'bg-[#D1E7DD] text-[#0F5132] border border-[#BADBCC]';
        case 'missed':
            return 'bg-[#FEE2E2] text-[#B91C1C] border border-[#FCA5A5]';
        case 'skipped':
            return 'bg-[#F1F5F9] text-[#64748B] border border-[#E2E8F0]';
        case 'below-target':
            return 'bg-[#FEF3C7] text-[#B45309] border border-[#FCD34D]';
        case 'referral-mandatory':
            return 'bg-[#DC2626] text-white border border-[#B91C1C] font-bold tracking-wide uppercase';
        case 'referral-recommended':
            return 'bg-[#F59E0B] text-white border border-[#D97706] font-semibold';
        case 'condition':
            return 'bg-[#E8F2EC] text-[#235347] border border-[#CDE3D5] font-medium';
        case 'neutral':
        default:
            return 'bg-[#F8FAFC] text-[#475569] border border-[#E2E8F0]';
    }
});

const sizeClasses = computed(() => {
    switch (props.size) {
        case 'sm':
            return 'text-[11px] px-2 py-0.5 gap-1';
        case 'md':
        default:
            return 'text-xs px-2.5 py-1 gap-1.5';
    }
});
</script>

<template>
    <span
        :class="[
            'inline-flex items-center rounded-full font-sans whitespace-nowrap select-none',
            variantClasses,
            sizeClasses,
        ]"
    >
        <slot name="icon">
            <svg
                v-if="variant === 'taken'"
                class="h-3.5 w-3.5 stroke-[2.5]"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M5 13l4 4L19 7"
                />
            </svg>
            <svg
                v-else-if="variant === 'missed'"
                class="h-3.5 w-3.5 stroke-[2.5]"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M6 18L18 6M6 6l12 12"
                />
            </svg>
            <svg
                v-else-if="variant === 'skipped'"
                class="h-3.5 w-3.5 stroke-2"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <circle cx="12" cy="12" r="9" />
                <path d="M4.93 4.93l14.14 14.14" />
            </svg>
            <svg
                v-else-if="variant === 'below-target'"
                class="h-3.5 w-3.5 stroke-2"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                />
            </svg>
        </slot>

        <slot />

        <button
            v-if="removable"
            type="button"
            class="ml-0.5 hover:opacity-75 focus:outline-none"
            @click.stop="emit('remove')"
        >
            <svg
                class="h-3 w-3 stroke-2"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M6 18L18 6M6 6l12 12"
                />
            </svg>
        </button>
    </span>
</template>
