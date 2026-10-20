<script setup lang="ts">
import ClinicalButton from './ClinicalButton.vue';

interface MedicationItem {
    id: string | number;
    name: string;
    dosage: string;
    taken?: boolean;
}

interface Props {
    timeOfDay?: string;
    scheduledTime?: string;
    medications: MedicationItem[];
}

withDefaults(defineProps<Props>(), {
    timeOfDay: 'Утро',
    scheduledTime: '8:00',
});

const emit = defineEmits<{
    (e: 'takeAll'): void;
}>();
</script>

<template>
    <div
        class="flex flex-col gap-4 rounded-3xl border border-[#EBE7DF] bg-[#FBF9F5] p-5 font-sans shadow-xs"
    >
        <div
            class="flex items-center justify-between border-b border-[#EBE7DF] pb-3"
        >
            <div
                class="flex items-center gap-1.5 text-xs font-bold text-[#1E293B]"
            >
                <span>☀️</span>
                <span
                    >{{ timeOfDay }} • {{ scheduledTime }} ({{
                        medications.length
                    }}
                    препарата)</span
                >
            </div>
            <span class="text-xs text-[#64748B]">Плановый приём</span>
        </div>

        <div class="flex flex-col gap-2.5">
            <div
                v-for="med in medications"
                :key="med.id"
                class="flex items-center justify-between rounded-2xl border border-[#E2E8F0] bg-white p-3"
            >
                <div class="flex items-center gap-3">
                    <span class="text-lg">💊</span>
                    <div>
                        <div class="text-sm font-bold text-[#1E293B]">
                            {{ med.name }}
                        </div>
                        <div class="text-xs text-[#64748B]">
                            {{ med.dosage }}
                        </div>
                    </div>
                </div>

                <span
                    v-if="med.taken"
                    class="rounded-full bg-[#D1E7DD] px-2.5 py-1 text-xs font-semibold text-[#0F5132]"
                >
                    ✓ Принято
                </span>
            </div>
        </div>

        <ClinicalButton
            variant="primary"
            shape="pill"
            size="md"
            full-width
            @click="emit('takeAll')"
        >
            Принять всё ({{ medications.length }} препарата)
        </ClinicalButton>
    </div>
</template>
