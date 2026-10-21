<script setup lang="ts">
import { computed } from 'vue';

export interface SelectOption {
    value: string | number;
    label: string;
    sublabel?: string;
    disabled?: boolean;
}

interface Props {
    modelValue: string | number;
    options: Array<string | SelectOption>;
    label?: string;
    hint?: string;
    error?: string;
    placeholder?: string;
    disabled?: boolean;
    required?: boolean;
    id?: string;
    size?: 'sm' | 'md' | 'lg';
}

const props = withDefaults(defineProps<Props>(), {
    disabled: false,
    required: false,
    size: 'md',
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const selectId = computed(
    () => props.id || 'clin-select-' + Math.random().toString(36).slice(2, 7),
);

const normalizedOptions = computed<SelectOption[]>(() => {
    return props.options.map((opt) => {
        if (typeof opt === 'string') {
            return { value: opt, label: opt };
        }
        return opt;
    });
});

const sizeClasses = computed(() => {
    switch (props.size) {
        case 'sm':
            return 'h-9 text-xs px-3 pr-9 rounded-lg';
        case 'lg':
            return 'h-14 text-base px-4 pr-12 rounded-2xl';
        case 'md':
        default:
            return 'h-12 text-sm px-4 pr-11 rounded-xl';
    }
});
</script>

<template>
    <div class="flex w-full flex-col gap-1.5 font-sans">
        <label
            v-if="label"
            :for="selectId"
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

            <select
                :id="selectId"
                :value="modelValue"
                :disabled="disabled"
                :required="required"
                :class="[
                    'w-full cursor-pointer appearance-none border bg-white font-medium text-[#1E293B] shadow-2xs transition-all duration-200 outline-none',
                    sizeClasses,
                    $slots.prefix ? 'pl-11' : '',
                    error
                        ? 'border-[#EF4444] focus:border-[#EF4444] focus:ring-2 focus:ring-[#FEE2E2]'
                        : 'border-[#E2E8F0] hover:border-[#CBD5E1] focus:border-[#235347] focus:ring-2 focus:ring-[#E8F2EC]',
                    disabled
                        ? 'cursor-not-allowed bg-[#F8FAFC] opacity-60'
                        : '',
                ]"
                @change="
                    emit(
                        'update:modelValue',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option
                    v-if="placeholder"
                    value=""
                    disabled
                    :selected="!modelValue"
                >
                    {{ placeholder }}
                </option>
                <option
                    v-for="opt in normalizedOptions"
                    :key="String(opt.value)"
                    :value="opt.value"
                    :disabled="opt.disabled"
                >
                    {{ opt.label
                    }}{{ opt.sublabel ? ` — ${opt.sublabel}` : '' }}
                </option>
            </select>

            <!-- Custom chevron dropdown icon, perfectly centered vertically -->
            <div
                class="pointer-events-none absolute top-1/2 right-3.5 flex -translate-y-1/2 items-center justify-center text-[#64748B]"
                aria-hidden="true"
            >
                <svg
                    class="h-4 w-4"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <polyline points="6 9 12 15 18 9" />
                </svg>
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
