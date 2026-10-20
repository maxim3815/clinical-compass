<script setup lang="ts">
interface Props {
    modelValue: number;
    step?: number;
    min?: number;
    max?: number;
    unit?: string;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    step: 1,
    min: 0.5,
    max: 10,
    unit: 'таблетка',
    disabled: false,
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: number): void;
}>();

function decrement() {
    if (props.modelValue > props.min) {
        const val = Math.max(
            props.min,
            Number((props.modelValue - props.step).toFixed(1)),
        );
        emit('update:modelValue', val);
    }
}

function increment() {
    if (props.modelValue < props.max) {
        const val = Math.min(
            props.max,
            Number((props.modelValue + props.step).toFixed(1)),
        );
        emit('update:modelValue', val);
    }
}
</script>

<template>
    <div
        class="inline-flex items-center rounded-2xl border border-[#E2E8F0] bg-[#F8FAFC] p-1 shadow-sm select-none"
    >
        <button
            type="button"
            :disabled="disabled || modelValue <= min"
            class="flex h-10 w-10 items-center justify-center rounded-xl border border-[#E2E8F0] bg-white text-lg font-bold text-[#1E293B] transition-colors hover:bg-[#F1F5F9] active:bg-[#E2E8F0] disabled:cursor-not-allowed disabled:opacity-40"
            @click="decrement"
        >
            −
        </button>

        <div class="min-w-[110px] px-5 text-center">
            <span class="text-base font-bold text-[#1E293B]">{{
                modelValue
            }}</span>
            <span class="ml-1 text-xs text-[#64748B]">{{ unit }}</span>
        </div>

        <button
            type="button"
            :disabled="disabled || modelValue >= max"
            class="flex h-10 w-10 items-center justify-center rounded-xl border border-[#E2E8F0] bg-white text-lg font-bold text-[#1E293B] transition-colors hover:bg-[#F1F5F9] active:bg-[#E2E8F0] disabled:cursor-not-allowed disabled:opacity-40"
            @click="increment"
        >
            +
        </button>
    </div>
</template>
