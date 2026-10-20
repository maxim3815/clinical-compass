<script setup lang="ts">
import ClinicalButton from './ClinicalButton.vue';

interface Props {
    timeOfDay?: string;
    scheduledTime?: string;
    medicationName: string;
    dosageText: string;
    categoryText?: string;
    status?: 'scheduled' | 'taken' | 'skipped' | 'not-due';
    notDueText?: string;
}

withDefaults(defineProps<Props>(), {
    timeOfDay: 'Morning',
    scheduledTime: '8:00 AM',
    categoryText: 'Antidepressant',
    status: 'scheduled',
    notDueText: 'Not due yet',
});

const emit = defineEmits<{
    (e: 'take'): void;
    (e: 'skip'): void;
}>();
</script>

<template>
    <div
        class="flex flex-col gap-4 rounded-3xl border border-[#EBE7DF] bg-[#FBF9F5] p-5 font-sans shadow-xs transition-all duration-200"
    >
        <div class="flex items-start justify-between">
            <div>
                <div
                    class="flex items-center gap-1.5 text-xs font-semibold text-[#64748B]"
                >
                    <span v-if="timeOfDay.toLowerCase().includes('morning')"
                        >☀️</span
                    >
                    <span
                        v-else-if="timeOfDay.toLowerCase().includes('evening')"
                        >🌙</span
                    >
                    <span v-else>🕒</span>
                    <span>{{ timeOfDay }} • {{ scheduledTime }}</span>
                </div>
                <h3 class="mt-1.5 text-xl font-bold text-[#1E293B]">
                    {{ medicationName }}
                </h3>
                <p class="mt-0.5 text-xs text-[#64748B]">
                    {{ dosageText }} • {{ categoryText }}
                </p>
            </div>

            <!-- Minimalist 3D-styled Pill Icon -->
            <div
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-[#E2E8F0] bg-white shadow-xs"
            >
                <div
                    class="flex h-6 w-6 -rotate-45 transform items-center justify-center rounded-full border-2 border-[#A3C9B8] bg-[#E8F2EC]"
                >
                    <div class="h-0.5 w-full bg-[#A3C9B8]"></div>
                </div>
            </div>
        </div>

        <div v-if="status === 'scheduled'" class="flex flex-col gap-2 pt-1">
            <ClinicalButton
                variant="primary"
                shape="pill"
                size="md"
                full-width
                @click="emit('take')"
            >
                <template #icon>
                    <svg
                        class="mr-1.5 h-4 w-4 stroke-[2.5]"
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
                </template>
                Taken
            </ClinicalButton>

            <ClinicalButton
                variant="secondary"
                shape="pill"
                size="md"
                full-width
                @click="emit('skip')"
            >
                Skip
            </ClinicalButton>
        </div>

        <div v-else-if="status === 'taken'" class="pt-1">
            <div
                class="flex h-12 items-center justify-center gap-2 rounded-full border border-[#BADBCC] bg-[#D1E7DD] text-sm font-semibold text-[#0F5132]"
            >
                <svg
                    class="h-4 w-4 stroke-[2.5]"
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
                <span>Принято в {{ scheduledTime }}</span>
            </div>
        </div>

        <div v-else-if="status === 'not-due'" class="pt-1">
            <div
                class="flex h-12 items-center justify-center gap-2 rounded-full border border-[#E2E8F0] bg-[#F1F5F9] text-sm font-medium text-[#64748B]"
            >
                <span>🕒</span>
                <span>{{ notDueText }}</span>
            </div>
        </div>

        <div v-else-if="status === 'skipped'" class="pt-1">
            <div
                class="flex h-12 items-center justify-center gap-2 rounded-full border border-[#FCA5A5] bg-[#FEE2E2] text-sm font-semibold text-[#B91C1C]"
            >
                <span>✕</span>
                <span>Пропущено</span>
            </div>
        </div>
    </div>
</template>
