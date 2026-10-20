<script setup lang="ts">
interface Option {
    value: string | number;
    label: string;
    sublabel?: string;
    icon?: string;
}

interface Props {
    modelValue: string | number;
    options: Option[];
}

defineProps<Props>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | number): void;
}>();
</script>

<template>
    <div
        class="inline-flex gap-1 rounded-full border border-[#E2E8F0] bg-[#F1F5F9] p-1 select-none"
    >
        <button
            v-for="opt in options"
            :key="opt.value"
            type="button"
            :class="[
                'flex items-center gap-2 rounded-full px-5 py-2.5 text-xs font-semibold transition-all duration-200 outline-none',
                modelValue === opt.value
                    ? 'bg-[#235347] text-white shadow-sm'
                    : 'text-[#64748B] hover:bg-white/60 hover:text-[#1E293B]',
            ]"
            @click="emit('update:modelValue', opt.value)"
        >
            <span v-if="opt.icon" class="text-sm">{{ opt.icon }}</span>
            <div class="flex flex-col text-left">
                <span>{{ opt.label }}</span>
                <span
                    v-if="opt.sublabel"
                    :class="[
                        'text-[10px] leading-tight font-normal',
                        modelValue === opt.value
                            ? 'text-white/80'
                            : 'text-[#94A3B8]',
                    ]"
                >
                    {{ opt.sublabel }}
                </span>
            </div>
        </button>
    </div>
</template>
