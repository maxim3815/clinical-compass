<script setup lang="ts">
import ClinicalButton from './ClinicalButton.vue';

interface Props {
    title?: string;
    details?: string;
    timestamp?: string;
}

withDefaults(defineProps<Props>(), {
    title: 'CRITICAL ALERT: Suicidal Ideation Signal (PHQ-9 Q9 = 2)',
    details:
        'Patient reports suicidal thoughts more than half the days. Requires immediate clinical contact.',
    timestamp: 'Received 4 min ago',
});

const emit = defineEmits<{
    (e: 'callPatient'): void;
    (e: 'acknowledge'): void;
}>();
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-3xl border-2 border-[#EF4444] bg-[#FEE2E2] p-5 font-sans shadow-sm"
    >
        <div class="flex items-start gap-3.5">
            <div
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[#DC2626] text-xl text-white shadow-xs"
            >
                ⚠️
            </div>
            <div class="flex-1">
                <div class="flex items-center justify-between">
                    <span
                        class="text-[11px] font-bold tracking-wider text-[#DC2626] uppercase"
                    >
                        Экстренный клинический сигнал
                    </span>
                    <span class="text-xs font-medium text-[#991B1B]">{{
                        timestamp
                    }}</span>
                </div>
                <h4
                    class="mt-0.5 text-base leading-snug font-bold text-[#991B1B]"
                >
                    {{ title }}
                </h4>
                <p class="mt-1 text-xs leading-relaxed text-[#7F1D1D]">
                    {{ details }}
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2.5 pt-1">
            <ClinicalButton
                variant="critical"
                shape="rounded"
                size="sm"
                @click="emit('callPatient')"
            >
                📞 Позвонить пациенту
            </ClinicalButton>

            <button
                type="button"
                class="rounded-xl border border-[#FCA5A5] bg-white px-4 py-2 text-xs font-semibold text-[#991B1B] transition-colors hover:bg-[#FEF2F2]"
                @click="emit('acknowledge')"
            >
                Подтвердить прочтение
            </button>
        </div>
    </div>
</template>
