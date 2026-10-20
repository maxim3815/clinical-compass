<script setup lang="ts">
import ClinicalButton from './ClinicalButton.vue';

interface Props {
    title?: string;
    description?: string;
    icon?: string;
    actionLabel?: string;
}

withDefaults(defineProps<Props>(), {
    title: 'Нет активных назначений',
    description:
        'Нажмите «Назначить препарат», чтобы составить протокол лечения для пациента.',
    icon: '🩺',
    actionLabel: '+ Назначить препарат',
});

const emit = defineEmits<{
    (e: 'action'): void;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center gap-3 rounded-3xl border-2 border-dashed border-[#CBD5E1] bg-[#F8FAFC] p-8 text-center font-sans"
    >
        <div
            class="flex h-14 w-14 items-center justify-center rounded-2xl border border-[#E2E8F0] bg-white text-2xl shadow-xs"
        >
            {{ icon }}
        </div>

        <div>
            <h4 class="text-base font-bold text-[#1E293B]">{{ title }}</h4>
            <p class="mt-1 max-w-sm text-xs leading-relaxed text-[#64748B]">
                {{ description }}
            </p>
        </div>

        <ClinicalButton
            v-if="actionLabel"
            variant="primary"
            shape="rounded"
            size="sm"
            class="mt-1"
            @click="emit('action')"
        >
            {{ actionLabel }}
        </ClinicalButton>
    </div>
</template>
