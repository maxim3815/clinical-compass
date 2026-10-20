<script setup lang="ts">
import ClinicalButton from './ClinicalButton.vue';

interface ExtractedFact {
    id: string;
    category: string;
    value: string;
    confidence: number;
}

interface Props {
    title?: string;
    rawTextExcerpt?: string;
    facts?: ExtractedFact[];
}

withDefaults(defineProps<Props>(), {
    title: 'Извлечение анамнеза из медицинской выписки (EHR Parsing)',
    rawTextExcerpt:
        'Пациентка 35 лет. Жалобы на постоянную тревогу, напряжение челюстей. В анамнезе СКФ 52 мл/мин (ХБП C3a). Диагноз F41.1 Генерализованное тревожное расстройство.',
    facts: () => [
        {
            id: '1',
            category: 'Диагноз МКБ',
            value: 'F41.1 Генерализованное тревожное расстройство',
            confidence: 96,
        },
        {
            id: '2',
            category: 'Клинический показатель',
            value: 'СКФ: 52 мл/мин (ХБП С3а)',
            confidence: 94,
        },
        {
            id: '3',
            category: 'Фактор безопасности',
            value: 'Противопоказание к Маритупирдину (СКФ < 60)',
            confidence: 91,
        },
    ],
});

const emit = defineEmits<{
    (e: 'apply'): void;
    (e: 'reject'): void;
}>();
</script>

<template>
    <div
        class="flex flex-col gap-4 rounded-3xl border border-[#E2E8F0] bg-white p-6 font-sans shadow-xs"
    >
        <div class="flex items-center justify-between">
            <h4 class="text-sm font-bold text-[#1E293B]">{{ title }}</h4>
            <span
                class="rounded-full bg-[#E8F2EC] px-2.5 py-0.5 text-xs font-semibold text-[#235347]"
            >
                AI Clinical NLP
            </span>
        </div>

        <!-- Raw excerpt with subtle highlight -->
        <div
            class="rounded-2xl border border-[#E2E8F0] bg-[#F8FAFC] p-3.5 text-xs leading-relaxed text-[#475569]"
        >
            <span class="mb-1 block font-semibold text-[#1E293B]"
                >Фрагмент текста:</span
            >
            {{ rawTextExcerpt }}
        </div>

        <div>
            <span class="mb-2 block text-xs font-semibold text-[#1E293B]">
                Распознанные клинические сущности:
            </span>
            <div class="flex flex-col gap-2">
                <div
                    v-for="fact in facts"
                    :key="fact.id"
                    class="flex items-center justify-between rounded-2xl border border-[#E2E8F0] bg-white p-3"
                >
                    <div>
                        <span
                            class="block text-[10px] font-bold text-[#64748B] uppercase"
                        >
                            {{ fact.category }}
                        </span>
                        <span
                            class="mt-0.5 block text-xs font-semibold text-[#1E293B]"
                        >
                            {{ fact.value }}
                        </span>
                    </div>

                    <span
                        class="rounded-full bg-[#D1E7DD] px-2.5 py-1 text-xs font-bold text-[#0F5132]"
                    >
                        {{ fact.confidence }}% точность
                    </span>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 pt-1">
            <ClinicalButton
                variant="primary"
                shape="rounded"
                size="sm"
                @click="emit('apply')"
            >
                Применить в карту пациента
            </ClinicalButton>

            <ClinicalButton
                variant="outline"
                shape="rounded"
                size="sm"
                @click="emit('reject')"
            >
                Отклонить
            </ClinicalButton>
        </div>
    </div>
</template>
