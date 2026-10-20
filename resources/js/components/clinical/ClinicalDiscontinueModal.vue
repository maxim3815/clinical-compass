<script setup lang="ts">
import { ref } from 'vue';
import ClinicalButton from './ClinicalButton.vue';

interface Props {
    drugName?: string;
    isOpen?: boolean;
}

withDefaults(defineProps<Props>(), {
    drugName: 'Авиандр (Маритупирдин) 20 мг',
    isOpen: true,
});

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'confirm', data: { reason: string; notes: string }): void;
}>();

const selectedReason = ref('Достигнута стойкая ремиссия');
const notes = ref('');

const reasons = [
    'Достигнута стойкая ремиссия',
    'Неэффективность терапии (отсутствие динамики)',
    'Нежелательные явления / непереносимость',
    'Отказ пациента от продолжения курса',
    'Смена схемы лечения на другой препарат',
];
</script>

<template>
    <div
        v-if="isOpen"
        class="flex w-full max-w-lg flex-col gap-4 rounded-3xl border border-[#E2E8F0] bg-white p-6 font-sans shadow-sm"
    >
        <div
            class="flex items-center justify-between border-b border-[#F1F5F9] pb-3"
        >
            <div>
                <h4 class="text-base font-bold text-[#1E293B]">
                    Отмена / Завершение назначения
                </h4>
                <p class="mt-0.5 text-xs font-medium text-[#DC2626]">
                    {{ drugName }}
                </p>
            </div>
            <button
                type="button"
                class="rounded-full p-1 text-[#94A3B8] hover:text-[#1E293B]"
                @click="emit('close')"
            >
                ✕
            </button>
        </div>

        <div class="flex flex-col gap-1.5">
            <label class="text-xs font-semibold text-[#1E293B]"
                >Причина завершения:</label
            >
            <select
                v-model="selectedReason"
                class="h-11 w-full rounded-xl border border-[#E2E8F0] bg-white px-3.5 text-sm text-[#1E293B] outline-none focus:border-[#235347] focus:ring-2 focus:ring-[#E8F2EC]"
            >
                <option v-for="r in reasons" :key="r" :value="r">
                    {{ r }}
                </option>
            </select>
        </div>

        <div class="flex flex-col gap-1.5">
            <label class="text-xs font-semibold text-[#1E293B]"
                >Клинические заметки:</label
            >
            <textarea
                v-model="notes"
                rows="3"
                placeholder="Комментарий врача о состоянии пациента при отмене..."
                class="w-full rounded-xl border border-[#E2E8F0] bg-white p-3 text-sm text-[#1E293B] outline-none placeholder:text-[#94A3B8] focus:border-[#235347] focus:ring-2 focus:ring-[#E8F2EC]"
            ></textarea>
        </div>

        <div
            class="flex items-center justify-end gap-2 border-t border-[#F1F5F9] pt-2"
        >
            <ClinicalButton
                variant="outline"
                shape="rounded"
                size="sm"
                @click="emit('close')"
            >
                Отмена
            </ClinicalButton>

            <button
                type="button"
                class="rounded-xl border border-[#FCA5A5] bg-[#FEE2E2] px-4 py-2 text-xs font-bold text-[#B91C1C] transition-colors hover:bg-[#FCD3D3]"
                @click="emit('confirm', { reason: selectedReason, notes })"
            >
                Подтвердить отмену назначения
            </button>
        </div>
    </div>
</template>
