<script setup lang="ts">
import ClinicalButton from './ClinicalButton.vue';

interface Props {
    medicationName: string;
    dosageText: string;
    takenToday: number;
    dailyLimit: number;
    limitExceededText?: string;
}

const props = withDefaults(defineProps<Props>(), {
    limitExceededText: 'Лимит на сегодня исчерпан',
});

const emit = defineEmits<{
    (e: 'take'): void;
}>();
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-3xl border border-[#E2E8F0] bg-white p-5 font-sans shadow-xs"
    >
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-[#1E293B]">
                    {{ medicationName }}
                </h3>
                <p class="mt-0.5 text-xs text-[#64748B]">{{ dosageText }}</p>
            </div>
            <span
                class="rounded-full border border-[#CDE3D5] bg-[#E8F2EC] px-2.5 py-0.5 text-xs font-medium text-[#235347]"
            >
                По необходимости
            </span>
        </div>

        <div>
            <div
                class="mb-1.5 flex items-center justify-between text-xs text-[#64748B]"
            >
                <span>Сегодня принято:</span>
                <span class="font-semibold text-[#1E293B]">
                    {{ takenToday }} из {{ dailyLimit }} разрешённых
                </span>
            </div>

            <!-- Segmented limit progress bar -->
            <div
                class="grid gap-1.5"
                :style="{
                    gridTemplateColumns: `repeat(${dailyLimit}, minmax(0, 1fr))`,
                }"
            >
                <div
                    v-for="i in dailyLimit"
                    :key="i"
                    :class="[
                        'h-2 rounded-full transition-all duration-300',
                        i <= takenToday
                            ? takenToday > dailyLimit
                                ? 'bg-[#EF4444]'
                                : 'bg-[#235347]'
                            : 'bg-[#E2E8F0]',
                    ]"
                ></div>
            </div>

            <p
                v-if="takenToday >= dailyLimit"
                class="mt-1.5 flex items-center gap-1 text-xs font-medium text-[#DC2626]"
            >
                <span>⚠️</span>
                <span>{{ limitExceededText }}</span>
            </p>
        </div>

        <ClinicalButton
            variant="secondary"
            shape="pill"
            size="md"
            full-width
            :disabled="takenToday >= dailyLimit"
            @click="emit('take')"
        >
            + Принять дозу (1 таб)
        </ClinicalButton>
    </div>
</template>
