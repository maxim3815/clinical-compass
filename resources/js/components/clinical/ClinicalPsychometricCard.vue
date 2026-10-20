<script setup lang="ts">
interface ScoreOption {
    value: number;
    label: string;
}

interface Props {
    title?: string;
    stepText?: string;
    question: string;
    modelValue: number | null;
    options?: ScoreOption[];
}

withDefaults(defineProps<Props>(), {
    title: 'GAD-7 Anxiety Screening',
    stepText: 'Question 3 of 7',
    options: () => [
        { value: 0, label: 'Not at all (0)' },
        { value: 1, label: 'Several days (1)' },
        { value: 2, label: 'More than half the days (2)' },
        { value: 3, label: 'Nearly every day (3)' },
    ],
});

const emit = defineEmits<{
    (e: 'update:modelValue', val: number): void;
}>();
</script>

<template>
    <div
        class="flex flex-col gap-5 rounded-3xl border border-[#E2E8F0] bg-white p-6 font-sans shadow-xs"
    >
        <div
            class="flex items-center justify-between border-b border-[#F1F5F9] pb-3"
        >
            <div>
                <h4 class="text-sm font-bold text-[#1E293B]">{{ title }}</h4>
                <span class="text-xs font-semibold text-[#235347]">{{
                    stepText
                }}</span>
            </div>
            <span class="text-xs text-[#94A3B8]">Шкала оценки</span>
        </div>

        <p class="text-base leading-relaxed font-medium text-[#1E293B]">
            {{ question }}
        </p>

        <div class="flex flex-col gap-2.5">
            <button
                v-for="opt in options"
                :key="opt.value"
                type="button"
                :class="[
                    'flex w-full items-center justify-between rounded-2xl border p-4 text-left text-sm font-medium transition-all outline-none',
                    modelValue === opt.value
                        ? 'border-[#235347] bg-[#235347] text-white shadow-xs'
                        : 'border-[#E2E8F0] bg-white text-[#1E293B] hover:border-[#235347] hover:bg-[#F8FAF9]',
                ]"
                @click="emit('update:modelValue', opt.value)"
            >
                <span>{{ opt.label }}</span>
                <span
                    :class="[
                        'ml-3 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2',
                        modelValue === opt.value
                            ? 'border-white bg-white'
                            : 'border-[#CBD5E1] bg-transparent',
                    ]"
                >
                    <span
                        v-if="modelValue === opt.value"
                        class="h-2.5 w-2.5 rounded-full bg-[#235347]"
                    ></span>
                </span>
            </button>
        </div>
    </div>
</template>
