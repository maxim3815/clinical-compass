<script setup lang="ts">
import ClinicalButton from './ClinicalButton.vue';

interface Props {
    modelValue: string;
    placeholder?: string;
    disabled?: boolean;
}

withDefaults(defineProps<Props>(), {
    placeholder: 'Type a message...',
    disabled: false,
});

const emit = defineEmits<{
    (e: 'update:modelValue', val: string): void;
    (e: 'send'): void;
    (e: 'attach'): void;
}>();

function onKeyDown(event: KeyboardEvent) {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
        event.preventDefault();
        emit('send');
    }
}
</script>

<template>
    <div
        class="flex items-center gap-2 rounded-full border border-[#E2E8F0] bg-white p-1.5 pl-4 font-sans shadow-xs transition-all focus-within:border-[#235347] focus-within:ring-2 focus-within:ring-[#E8F2EC]"
    >
        <button
            type="button"
            class="rounded-full p-1.5 text-[#64748B] transition-colors hover:bg-[#F1F5F9] hover:text-[#1E293B]"
            @click="emit('attach')"
        >
            <svg
                class="h-5 w-5 stroke-2"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"
                />
            </svg>
        </button>

        <input
            type="text"
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            class="flex-1 bg-transparent text-sm text-[#1E293B] outline-none placeholder:text-[#94A3B8]"
            @input="
                emit(
                    'update:modelValue',
                    ($event.target as HTMLInputElement).value,
                )
            "
            @keydown="onKeyDown"
        />

        <ClinicalButton
            variant="primary"
            shape="circle"
            size="sm"
            :disabled="!modelValue.trim() || disabled"
            @click="emit('send')"
        >
            <svg
                class="-ml-0.5 h-4 w-4 rotate-45 transform stroke-2"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"
                />
            </svg>
        </ClinicalButton>
    </div>
</template>
