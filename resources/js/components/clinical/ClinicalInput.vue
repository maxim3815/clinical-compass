<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    modelValue: string | number;
    label?: string;
    hint?: string;
    error?: string;
    placeholder?: string;
    type?: string;
    disabled?: boolean;
    required?: boolean;
    id?: string;
}

const props = withDefaults(defineProps<Props>(), {
    type: 'text',
    disabled: false,
    required: false,
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const inputId = computed(
    () => props.id || 'clin-input-' + Math.random().toString(36).slice(2, 7),
);
</script>

<template>
    <div class="flex w-full flex-col gap-1.5 font-sans">
        <label
            v-if="label"
            :for="inputId"
            class="flex items-center justify-between text-xs font-semibold text-[#1E293B]"
        >
            <span>
                {{ label }}
                <span v-if="required" class="text-[#EF4444]">*</span>
            </span>
            <slot name="labelAction" />
        </label>

        <div class="relative flex items-center">
            <div
                v-if="$slots.prefix"
                class="pointer-events-none absolute left-3.5 flex items-center text-[#64748B]"
            >
                <slot name="prefix" />
            </div>

            <input
                :id="inputId"
                :type="type"
                :value="modelValue"
                :placeholder="placeholder"
                :disabled="disabled"
                :required="required"
                :class="[
                    'h-12 w-full rounded-xl border bg-white px-4 text-sm text-[#1E293B] transition-all duration-200 outline-none',
                    'placeholder:text-[#94A3B8]',
                    $slots.prefix ? 'pl-11' : '',
                    $slots.suffix ? 'pr-11' : '',
                    error
                        ? 'border-[#EF4444] focus:border-[#EF4444] focus:ring-2 focus:ring-[#FEE2E2]'
                        : 'border-[#E2E8F0] focus:border-[#235347] focus:ring-2 focus:ring-[#E8F2EC]',
                    disabled
                        ? 'cursor-not-allowed bg-[#F8FAFC] opacity-60'
                        : '',
                ]"
                @input="
                    emit(
                        'update:modelValue',
                        ($event.target as HTMLInputElement).value,
                    )
                "
            />

            <div
                v-if="$slots.suffix"
                class="absolute right-3.5 flex items-center text-[#64748B]"
            >
                <slot name="suffix" />
            </div>
        </div>

        <p v-if="error" class="mt-0.5 text-xs font-medium text-[#DC2626]">
            {{ error }}
        </p>
        <p v-else-if="hint" class="mt-0.5 text-xs text-[#64748B]">
            {{ hint }}
        </p>
    </div>
</template>
