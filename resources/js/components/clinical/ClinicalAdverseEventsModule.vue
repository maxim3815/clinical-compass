<script setup lang="ts">
import { ref } from 'vue';
import ClinicalButton from './ClinicalButton.vue';

interface Symptom {
    id: string;
    label: string;
    isUrgent?: boolean;
}

interface Props {
    title?: string;
    symptoms?: Symptom[];
}

const props = withDefaults(defineProps<Props>(), {
    title: 'Report Side Effect',
    symptoms: () => [
        { id: 'drowsiness', label: 'Drowsiness' },
        { id: 'nausea', label: 'Nausea' },
        { id: 'trismus', label: 'Jaw Tension / Trismus ⚠️', isUrgent: true },
        { id: 'headache', label: 'Headache' },
        { id: 'dizziness', label: 'Dizziness' },
    ],
});

const emit = defineEmits<{
    (e: 'submit', data: { selectedSymptoms: string[]; severity: number }): void;
}>();

const selectedSymptoms = ref<string[]>([]);
const severity = ref<number>(1);

function toggleSymptom(id: string) {
    const idx = selectedSymptoms.value.indexOf(id);
    if (idx > -1) {
        selectedSymptoms.value.splice(idx, 1);
    } else {
        selectedSymptoms.value.push(id);
    }
}

function submit() {
    emit('submit', {
        selectedSymptoms: selectedSymptoms.value,
        severity: severity.value,
    });
}
</script>

<template>
    <div
        class="flex flex-col gap-4 rounded-3xl border border-[#E2E8F0] bg-white p-6 font-sans shadow-xs"
    >
        <div class="flex items-center justify-between">
            <h4 class="text-base font-bold text-[#1E293B]">{{ title }}</h4>
            <span class="text-xs text-[#64748B]">Мониторинг безопасности</span>
        </div>

        <div>
            <span class="mb-2 block text-xs font-semibold text-[#64748B]">
                Выберите симптомы:
            </span>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="sym in symptoms"
                    :key="sym.id"
                    type="button"
                    :class="[
                        'rounded-full border px-3 py-1.5 text-xs font-medium transition-all',
                        selectedSymptoms.includes(sym.id)
                            ? sym.isUrgent
                                ? 'border-[#EF4444] bg-[#FEE2E2] text-[#B91C1C]'
                                : 'border-[#235347] bg-[#235347] text-white'
                            : sym.isUrgent
                              ? 'border-[#FCA5A5] bg-white text-[#B91C1C] hover:bg-[#FEF2F2]'
                              : 'border-[#E2E8F0] bg-[#F8FAFC] text-[#475569] hover:border-[#235347]',
                    ]"
                    @click="toggleSymptom(sym.id)"
                >
                    {{ sym.label }}
                </button>
            </div>
        </div>

        <div>
            <span class="mb-2 block text-xs font-semibold text-[#64748B]">
                Степень выраженности:
            </span>
            <div class="grid grid-cols-3 gap-2">
                <button
                    v-for="sev in [
                        { level: 1, label: 'Легкая (1)' },
                        { level: 2, label: 'Умеренная (2)' },
                        { level: 3, label: 'Тяжелая (3)' },
                    ]"
                    :key="sev.level"
                    type="button"
                    :class="[
                        'rounded-xl border px-3 py-2 text-xs font-semibold transition-all',
                        severity === sev.level
                            ? 'border-[#235347] bg-[#E8F2EC] text-[#235347] shadow-xs'
                            : 'border-[#E2E8F0] bg-white text-[#64748B] hover:bg-[#F8FAFC]',
                    ]"
                    @click="severity = sev.level"
                >
                    {{ sev.label }}
                </button>
            </div>
        </div>

        <ClinicalButton
            variant="primary"
            shape="pill"
            size="md"
            full-width
            :disabled="!selectedSymptoms.length"
            @click="submit"
        >
            Отправить врачу
        </ClinicalButton>
    </div>
</template>
