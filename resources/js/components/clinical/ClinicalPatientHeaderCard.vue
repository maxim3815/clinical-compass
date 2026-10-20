<script setup lang="ts">
import ClinicalButton from './ClinicalButton.vue';
import ClinicalBadge from './ClinicalBadge.vue';

interface Props {
    name: string;
    avatarUrl?: string;
    gender?: string;
    age?: number;
    patientId: string;
    conditions?: string[];
    isOnline?: boolean;
}

withDefaults(defineProps<Props>(), {
    conditions: () => ['Anxiety', 'Depression'],
    isOnline: true,
});

const emit = defineEmits<{
    (e: 'viewProfile'): void;
    (e: 'moreActions'): void;
}>();
</script>

<template>
    <div
        class="flex flex-col justify-between gap-4 rounded-3xl border border-[#E2E8F0] bg-white p-6 font-sans shadow-sm md:flex-row md:items-center"
    >
        <div class="flex items-center gap-4">
            <div class="relative shrink-0">
                <img
                    v-if="avatarUrl"
                    :src="avatarUrl"
                    :alt="name"
                    class="h-16 w-16 rounded-full border-2 border-white object-cover shadow-xs"
                />
                <div
                    v-else
                    class="flex h-16 w-16 items-center justify-center rounded-full border-2 border-white bg-[#E8F2EC] text-xl font-bold text-[#235347] shadow-xs"
                >
                    {{ name.charAt(0) }}
                </div>

                <!-- Online dot indicator -->
                <span
                    v-if="isOnline"
                    class="absolute right-0 bottom-0 h-4 w-4 rounded-full border-2 border-white bg-[#22C55E]"
                ></span>
            </div>

            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-xl font-bold text-[#1E293B]">{{ name }}</h2>
                </div>

                <p class="mt-0.5 text-xs text-[#64748B]">
                    <span v-if="gender">{{ gender }} • </span>
                    <span v-if="age">{{ age }} y/o • </span>
                    <span>ID: {{ patientId }}</span>
                </p>

                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    <ClinicalBadge
                        v-for="cond in conditions"
                        :key="cond"
                        variant="condition"
                        size="sm"
                    >
                        {{ cond }}
                    </ClinicalBadge>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 self-end md:self-center">
            <ClinicalButton
                variant="outline"
                shape="rounded"
                size="md"
                @click="emit('viewProfile')"
            >
                <span>View full profile</span>
                <template #trailingIcon>
                    <svg
                        class="ml-1.5 h-4 w-4 stroke-2"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M14 5l7 7m0 0l-7 7m7-7H3"
                        />
                    </svg>
                </template>
            </ClinicalButton>

            <button
                type="button"
                class="flex h-10 w-10 items-center justify-center rounded-xl border border-[#E2E8F0] bg-white text-base text-[#64748B] transition-colors hover:bg-[#F8FAFC] hover:text-[#1E293B]"
                @click="emit('moreActions')"
            >
                •••
            </button>
        </div>
    </div>
</template>
