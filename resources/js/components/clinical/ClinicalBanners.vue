<script setup lang="ts">
import { computed } from 'vue';
import ClinicalButton from './ClinicalButton.vue';

interface Props {
    type?: 'push' | 'pwa' | 'toast';
    title?: string;
    description?: string;
}

const props = withDefaults(defineProps<Props>(), {
    type: 'push',
});

const emit = defineEmits<{
    (e: 'primaryAction'): void;
    (e: 'dismiss'): void;
}>();

const displayTitle = computed(() => {
    if (props.title) return props.title;
    switch (props.type) {
        case 'pwa':
            return 'Установите приложение';
        case 'toast':
            return 'Приём отмечен! Отличная последовательность 🌱';
        case 'push':
        default:
            return 'Включите напоминания о приёме';
    }
});

const displayDescription = computed(() => {
    if (props.description) return props.description;
    switch (props.type) {
        case 'pwa':
            return 'Добавьте на экран «Домой» для быстрого входа в 1 тап';
        case 'push':
        default:
            return 'Чтобы не пропускать приём препаратов и вовремя подтверждать дозы.';
    }
});
</script>

<template>
    <!-- Push Banner -->
    <div
        v-if="type === 'push'"
        class="flex flex-col justify-between gap-4 rounded-3xl border border-[#BADBCC] bg-[#EAF4EE] p-5 font-sans shadow-xs sm:flex-row sm:items-center"
    >
        <div class="flex items-center gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-white text-lg text-[#235347] shadow-2xs"
            >
                🔔
            </div>
            <div>
                <h4 class="text-sm font-bold text-[#1E293B]">{{ displayTitle }}</h4>
                <p class="mt-0.5 text-xs text-[#64748B]">{{ displayDescription }}</p>
            </div>
        </div>

        <div class="flex items-center gap-2 self-end sm:self-center">
            <ClinicalButton
                variant="primary"
                shape="pill"
                size="sm"
                @click="emit('primaryAction')"
            >
                Включить
            </ClinicalButton>
            <ClinicalButton
                variant="ghost"
                shape="pill"
                size="sm"
                @click="emit('dismiss')"
            >
                Позже
            </ClinicalButton>
        </div>
    </div>

    <!-- PWA Install Banner -->
    <div
        v-else-if="type === 'pwa'"
        class="flex flex-col justify-between gap-4 rounded-3xl border border-[#E2E8F0] bg-white p-5 font-sans shadow-xs sm:flex-row sm:items-center"
    >
        <div class="flex items-center gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-[#235347] text-lg text-white shadow-2xs"
            >
                🧭
            </div>
            <div>
                <h4 class="text-sm font-bold text-[#1E293B]">
                    {{ displayTitle }}
                </h4>
                <p class="mt-0.5 text-xs text-[#64748B]">
                    {{ displayDescription }}
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2 self-end sm:self-center">
            <ClinicalButton
                variant="primary"
                shape="pill"
                size="sm"
                @click="emit('primaryAction')"
            >
                Установить
            </ClinicalButton>
            <button
                type="button"
                class="px-2 py-1 text-xs text-[#94A3B8] hover:text-[#64748B]"
                @click="emit('dismiss')"
            >
                ✕
            </button>
        </div>
    </div>

    <!-- Success Celebration Toast -->
    <div
        v-else-if="type === 'toast'"
        class="inline-flex items-center gap-2.5 rounded-full border border-[#BADBCC] bg-[#D1E7DD] px-4 py-2.5 font-sans text-xs font-semibold text-[#0F5132] shadow-md select-none"
    >
        <span
            class="flex h-5 w-5 items-center justify-center rounded-full bg-[#0F5132] text-[10px] text-white"
        >
            ✓
        </span>
        <span>{{ displayTitle }}</span>
    </div>
</template>
