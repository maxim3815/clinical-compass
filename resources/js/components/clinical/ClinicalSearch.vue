<script setup lang="ts">
interface Props {
    modelValue: string;
    placeholder?: string;
}

withDefaults(defineProps<Props>(), {
    placeholder: 'Search patients...',
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'clear'): void;
}>();
</script>

<template>
    <div class="relative flex w-full max-w-md items-center">
        <div
            class="pointer-events-none absolute left-4 flex items-center text-[#64748B]"
        >
            <svg
                class="h-4 w-4 stroke-2"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <circle cx="11" cy="11" r="8" />
                <path d="M21 21l-4.35-4.35" stroke-linecap="round" />
            </svg>
        </div>

        <input
            type="text"
            :value="modelValue"
            :placeholder="placeholder"
            class="h-11 w-full rounded-full border border-[#E2E8F0] bg-white pr-10 pl-11 text-sm text-[#1E293B] shadow-sm transition-all duration-200 outline-none placeholder:text-[#94A3B8] focus:border-[#235347] focus:ring-2 focus:ring-[#E8F2EC]"
            @input="
                emit(
                    'update:modelValue',
                    ($event.target as HTMLInputElement).value,
                )
            "
        />

        <button
            v-if="modelValue"
            type="button"
            class="absolute right-3.5 rounded-full p-1 text-[#94A3B8] hover:bg-[#F1F5F9] hover:text-[#1E293B]"
            @click="
                emit('update:modelValue', '');
                emit('clear');
            "
        >
            <svg
                class="h-3.5 w-3.5 stroke-2"
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
    </div>
</template>
