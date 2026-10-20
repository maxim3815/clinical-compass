<script setup lang="ts">
interface Step {
    week: string;
    dosage: string;
    note: string;
    isTarget?: boolean;
}

interface Props {
    drugName?: string;
    steps?: Step[];
}

withDefaults(defineProps<Props>(), {
    drugName: 'Авиандр (Маритупирдин)',
    steps: () => [
        {
            week: 'Неделя 1',
            dosage: '20 мг/сут',
            note: 'Стартовая доза (10 мг 2 р/сут)',
        },
        {
            week: 'Неделя 2–4',
            dosage: '40 мг/сут',
            note: 'Терапевтическая целевая',
            isTarget: true,
        },
        { week: 'Неделя 5+', dosage: '40 мг/сут', note: 'Поддерживающий курс' },
        { week: 'Завершение', dosage: '20 мг/сут', note: 'Постепенная отмена' },
    ],
});
</script>

<template>
    <div
        class="flex flex-col gap-4 rounded-3xl border border-[#E2E8F0] bg-white p-6 font-sans shadow-xs"
    >
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-sm font-bold text-[#1E293B]">
                    Схема титрации и отмены: {{ drugName }}
                </h4>
                <p class="mt-0.5 text-xs text-[#64748B]">
                    Клинический протокол ведения
                </p>
            </div>

            <span
                class="rounded-full bg-[#D1E7DD] px-3 py-1 text-xs font-semibold text-[#0F5132]"
            >
                ✓ Соответствует КР Минздрава РФ
            </span>
        </div>

        <div class="grid grid-cols-1 gap-3 pt-2 md:grid-cols-4">
            <div
                v-for="(st, idx) in steps"
                :key="st.week"
                :class="[
                    'flex flex-col gap-1 rounded-2xl border p-4 transition-all',
                    st.isTarget
                        ? 'border-[#235347] bg-[#E8F2EC]/60 shadow-xs'
                        : 'border-[#E2E8F0] bg-[#F8FAFC]',
                ]"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-[#64748B]">{{
                        st.week
                    }}</span>
                    <span
                        v-if="st.isTarget"
                        class="rounded-full border border-[#BADBCC] bg-white px-2 py-0.5 text-[10px] font-bold text-[#235347]"
                    >
                        Цель
                    </span>
                </div>

                <div class="mt-1 text-base font-bold text-[#1E293B]">
                    {{ st.dosage }}
                </div>
                <div class="text-[11px] leading-tight text-[#64748B]">
                    {{ st.note }}
                </div>
            </div>
        </div>
    </div>
</template>
