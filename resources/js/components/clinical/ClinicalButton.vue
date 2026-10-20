<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    variant?:
        | 'primary'
        | 'secondary'
        | 'outline'
        | 'warning'
        | 'critical'
        | 'pending'
        | 'ghost';
    shape?: 'pill' | 'rounded' | 'circle';
    size?: 'sm' | 'md' | 'lg';
    disabled?: boolean;
    loading?: boolean;
    type?: 'button' | 'submit' | 'reset';
    fullWidth?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'primary',
    shape: 'pill',
    size: 'md',
    disabled: false,
    loading: false,
    type: 'button',
    fullWidth: false,
});

const emit = defineEmits<{
    (e: 'click', event: MouseEvent): void;
}>();

const variantClasses = computed(() => {
    switch (props.variant) {
        case 'primary':
            return 'bg-[#235347] text-white hover:bg-[#1B4339] active:bg-[#15342C] shadow-sm';
        case 'secondary':
            return 'bg-[#E8F2EC] text-[#235347] hover:bg-[#DCEAE1] active:bg-[#CFE2D6]';
        case 'outline':
            return 'bg-white text-[#1E293B] border border-[#E2E8F0] hover:bg-[#F8FAF9] hover:border-[#CBD5E1]';
        case 'warning':
            return 'bg-[#FEF3C7] text-[#B45309] hover:bg-[#FDE68A] border border-[#FCD34D]';
        case 'critical':
            return 'bg-[#EF4444] text-white hover:bg-[#DC2626] active:bg-[#B91C1C] shadow-sm';
        case 'pending':
            return 'bg-[#F1F5F9] text-[#64748B] hover:bg-[#E2E8F0] cursor-not-allowed';
        case 'ghost':
            return 'bg-transparent text-[#235347] hover:bg-[#E8F2EC]/50';
        default:
            return 'bg-[#235347] text-white';
    }
});

const shapeClasses = computed(() => {
    switch (props.shape) {
        case 'pill':
            return 'rounded-full';
        case 'rounded':
            return 'rounded-xl';
        case 'circle':
            return 'rounded-full aspect-square p-0 flex items-center justify-center';
        default:
            return 'rounded-full';
    }
});

const sizeClasses = computed(() => {
    if (props.shape === 'circle') {
        switch (props.size) {
            case 'sm':
                return 'w-8 h-8';
            case 'md':
                return 'w-11 h-11';
            case 'lg':
                return 'w-14 h-14';
        }
    }

    switch (props.size) {
        case 'sm':
            return 'h-9 px-3.5 text-xs font-medium gap-1.5';
        case 'md':
            return 'h-12 px-5 text-sm font-semibold gap-2';
        case 'lg':
            return 'h-14 px-7 text-base font-semibold gap-2.5';
        default:
            return 'h-12 px-5 text-sm font-semibold gap-2';
    }
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading || variant === 'pending'"
        :class="[
            'inline-flex items-center justify-center font-sans transition-all duration-200 outline-none select-none',
            'focus-visible:ring-2 focus-visible:ring-[#235347] focus-visible:ring-offset-2',
            variantClasses,
            shapeClasses,
            sizeClasses,
            fullWidth ? 'w-full' : '',
            disabled
                ? 'cursor-not-allowed opacity-50 shadow-none'
                : 'active:scale-[0.98]',
        ]"
        @click="emit('click', $event)"
    >
        <svg
            v-if="loading"
            class="mr-2 -ml-1 h-4 w-4 animate-spin text-current"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
            ></circle>
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8v8H4z"
            ></path>
        </svg>

        <slot name="icon" />
        <slot />
        <slot name="trailingIcon" />
    </button>
</template>
