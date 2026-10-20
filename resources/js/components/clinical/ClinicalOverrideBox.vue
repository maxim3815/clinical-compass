<script setup lang="ts">
import ClinicalButton from './ClinicalButton.vue';

interface Props {
    modelValue: string;
    warningMessage?: string;
    placeholder?: string;
    disabled?: boolean;
}

withDefaults(defineProps<Props>(), {
    warningMessage:
        'Назначение содержит предупреждения безопасности. Требуется внести клиническое обоснование для аудита.',
    placeholder:
        'Укажите клиническое обоснование назначения вопреки предупреждению...',
    disabled: false,
});

const emit = defineEmits<{
    (e: 'update:modelValue', val: string): void;
    (e: 'confirm'): void;
}>();
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-3xl border border-[#FCA5A5] bg-white p-5 font-sans shadow-xs"
    >
        <div class="flex items-center gap-2 text-xs font-bold text-[#DC2626]">
            <span>⚠️</span>
            <span>Клиническое обоснование (Clinical Override)</span>
        </div>

        <p class="text-xs leading-relaxed text-[#64748B]">
            {{ warningMessage }}
        </p>

        <textarea
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            rows="3"
            class="w-full resize-y rounded-2xl border border-[#FCA5A5] bg-[#FFF5F5] p-3.5 text-sm text-[#1E293B] transition-all outline-none placeholder:text-[#94A3B8] focus:border-[#DC2626] focus:ring-2 focus:ring-[#FEE2E2]"
            @input="
                emit(
                    'update:modelValue',
                    ($event.target as HTMLTextAreaElement).value,
                )
            "
        ></textarea>

        <div class="flex justify-end pt-1">
            <ClinicalButton
                variant="primary"
                shape="rounded"
                size="sm"
                :disabled="!modelValue.trim() || disabled"
                @click="emit('confirm')"
            >
                Подтвердить обоснование
            </ClinicalButton>
        </div>
    </div>
</template>
