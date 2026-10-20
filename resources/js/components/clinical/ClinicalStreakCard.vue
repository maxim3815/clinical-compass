<script setup lang="ts">
interface Props {
    streakDays: number;
    completedDays?: boolean[];
    title?: string;
    subtitle?: string;
}

withDefaults(defineProps<Props>(), {
    streakDays: 7,
    completedDays: () => [true, true, true, true, true, true, true],
    title: '7 day streak',
    subtitle: "Great consistency! You're building healthy habits.",
});
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-2xl border border-[#E2E8F0] bg-white p-5 font-sans shadow-sm"
    >
        <div class="flex items-center gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-[#FEF3C7] text-xl text-[#D97706]"
            >
                🔥
            </div>
            <div>
                <h4 class="text-sm font-bold text-[#1E293B]">{{ title }}</h4>
                <p class="mt-0.5 text-xs text-[#64748B]">{{ subtitle }}</p>
            </div>
        </div>

        <div class="flex items-center gap-2 pt-1">
            <div
                v-for="(checked, i) in completedDays"
                :key="i"
                :class="[
                    'flex h-7 w-7 items-center justify-center rounded-full transition-all',
                    checked
                        ? 'bg-[#235347] text-white shadow-xs'
                        : 'bg-[#F1F5F9] text-[#94A3B8]',
                ]"
            >
                <svg
                    v-if="checked"
                    class="h-3.5 w-3.5 stroke-[2.5]"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M5 13l4 4L19 7"
                    />
                </svg>
                <span v-else class="text-[10px] font-bold">{{ i + 1 }}</span>
            </div>
        </div>
    </div>
</template>
