<script setup lang="ts">
import { ref } from 'vue';
import ClinicalButton from './ClinicalButton.vue';

interface Props {
    title?: string;
    isOpen?: boolean;
}

withDefaults(defineProps<Props>(), {
    title: 'Причина пропуска приёма',
    isOpen: true,
});

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'confirm', data: { reason: string; comment: string }): void;
}>();

const reasons = [
    'Забыл принять',
    'Плохо себя чувствовал / побочные эффекты',
    'Закончился препарат',
    'Другая причина',
];

const selectedReason = ref('Забыл принять');
const comment = ref('');
</script>

<template>
    <div
        v-if="isOpen"
        class="flex w-full max-w-sm flex-col gap-4 rounded-3xl border border-[#E2E8F0] bg-white p-6 font-sans shadow-sm"
    >
        <div
            class="flex items-center justify-between border-b border-[#F1F5F9] pb-3"
        >
            <h4 class="text-base font-bold text-[#1E293B]">{{ title }}</h4>
            <button
                type="button"
                class="text-[#94A3B8] hover:text-[#1E293B]"
                @click="emit('close')"
            >
                ✕
            </button>
        </div>

        <div class="flex flex-col gap-2">
            <label
                v-for="r in reasons"
                :key="r"
                class="flex cursor-pointer items-center justify-between rounded-2xl border p-3 text-xs font-semibold transition-all"
                :class="[
                    selectedReason === r
                        ? 'border-[#235347] bg-[#E8F2EC]/60 text-[#235347]'
                        : 'border-[#E2E8F0] bg-white text-[#1E293B] hover:border-[#CBD5E1]',
                ]"
            >
                <span>{{ r }}</span>
                <input
                    v-model="selectedReason"
                    type="radio"
                    :value="r"
                    class="h-4 w-4 accent-[#235347]"
                />
            </label>
        </div>

        <input
            v-model="comment"
            type="text"
            placeholder="Комментарий (по желанию)..."
            class="h-11 w-full rounded-xl border border-[#E2E8F0] bg-[#F8FAFC] px-4 text-xs text-[#1E293B] transition-all outline-none placeholder:text-[#94A3B8] focus:border-[#235347] focus:bg-white"
        />

        <div class="flex flex-col gap-2 pt-1">
            <ClinicalButton
                variant="primary"
                shape="pill"
                size="md"
                full-width
                @click="emit('confirm', { reason: selectedReason, comment })"
            >
                Подтвердить пропуск
            </ClinicalButton>

            <ClinicalButton
                variant="outline"
                shape="pill"
                size="md"
                full-width
                @click="emit('close')"
            >
                Отмена
            </ClinicalButton>
        </div>
    </div>
</template>
