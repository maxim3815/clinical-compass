<script setup lang="ts">
import { ref } from 'vue';

type DayStatus = 'on-time' | 'late' | 'missed' | 'partial' | 'future';

interface DayItem {
    dayNumber: number;
    status: DayStatus;
    details?: string;
}

interface Props {
    title?: string;
    days?: DayItem[];
}

const props = withDefaults(defineProps<Props>(), {
    title: '28-Day Adherence Heatmap Matrix',
    days: () => {
        // default 28 days mock
        const sample: DayItem[] = [];
        for (let i = 1; i <= 28; i++) {
            let status: DayStatus = 'on-time';
            let details = `Day ${i} • Принято вовремя 8:00 и 20:00`;
            if (i === 5 || i === 7 || i === 13 || i === 24) {
                status = 'late';
                details = `Day ${i} • Утро 8:05 ✓ • Вечер 21:30 ⚠️ (опоздание)`;
            } else if (i === 6 || i === 9 || i === 11 || i === 18) {
                status = 'missed';
                details = `Day ${i} • Вечер 20:00 ✕ (пропуск: закончился препарат)`;
            } else if (i === 19) {
                status = 'partial';
                details = `Day ${i} • Принят 1 из 2 приёмов`;
            } else if (i >= 26) {
                status = 'future';
                details = `Day ${i} • Запланировано`;
            }
            sample.push({ dayNumber: i, status, details });
        }
        return sample;
    },
});

const hoveredDay = ref<DayItem | null>(null);

function getStatusClasses(status: DayStatus) {
    switch (status) {
        case 'on-time':
            return 'bg-[#235347] text-white';
        case 'late':
            return 'bg-[#F59E0B] text-white';
        case 'missed':
            return 'bg-[#EF4444] text-white';
        case 'partial':
            return 'bg-[#EF4444]/60 text-white';
        case 'future':
        default:
            return 'bg-[#F1F5F9] text-[#94A3B8] border border-[#E2E8F0]';
    }
}
</script>

<template>
    <div
        class="relative flex flex-col gap-4 rounded-3xl border border-[#E2E8F0] bg-white p-6 font-sans shadow-xs"
    >
        <div class="flex items-center justify-between">
            <h4 class="text-sm font-bold text-[#1E293B]">{{ title }}</h4>
            <div class="flex items-center gap-3 text-[11px] text-[#64748B]">
                <span class="flex items-center gap-1">
                    <span class="h-2.5 w-2.5 rounded-full bg-[#235347]"></span>
                    Вовремя
                </span>
                <span class="flex items-center gap-1">
                    <span class="h-2.5 w-2.5 rounded-full bg-[#F59E0B]"></span>
                    Поздно
                </span>
                <span class="flex items-center gap-1">
                    <span class="h-2.5 w-2.5 rounded-full bg-[#EF4444]"></span>
                    Пропуск
                </span>
            </div>
        </div>

        <!-- 4 x 7 Days Grid -->
        <div class="grid grid-cols-7 gap-2">
            <div
                v-for="d in days"
                :key="d.dayNumber"
                :class="[
                    'relative flex h-12 cursor-pointer flex-col items-center justify-center rounded-xl text-xs font-bold shadow-2xs transition-all duration-150 hover:scale-105',
                    getStatusClasses(d.status),
                ]"
                @mouseenter="hoveredDay = d"
                @mouseleave="hoveredDay = null"
            >
                <span class="text-[9px] leading-none font-normal opacity-75"
                    >Day</span
                >
                <span class="mt-0.5 leading-none">{{ d.dayNumber }}</span>
            </div>
        </div>

        <!-- Popover Info Tooltip -->
        <div
            v-if="hoveredDay"
            class="flex items-center justify-between rounded-2xl bg-[#1E293B] p-3 text-xs text-white shadow-lg"
        >
            <span>{{ hoveredDay.details }}</span>
            <span class="ml-2 text-[10px] font-semibold text-[#A3C9B8]"
                >Нажмите для разбора</span
            >
        </div>
        <div
            v-else
            class="rounded-2xl border border-[#E2E8F0] bg-[#F8FAFC] p-3 text-center text-xs text-[#64748B]"
        >
            Наведите на любой день курса для просмотра информации о приёмах
        </div>
    </div>
</template>
