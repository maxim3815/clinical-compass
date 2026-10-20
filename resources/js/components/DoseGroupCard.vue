<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import DoseController from '@/actions/App/Http/Controllers/Patient/DoseController';
import DoseItem from '@/components/DoseItem.vue';
import { Button } from '@/components/ui/button';
import type { Dose, DoseGroup } from '@/types';

const props = defineProps<{
    group: DoseGroup;
    // Пациент пришёл из напоминания об этих приёмах.
    highlighted?: boolean;
}>();

const emit = defineEmits<{
    mark: [dose: Dose, status: 'taken' | 'skipped'];
}>();

// «Принял всё» отмечает приёмы, которые ещё не отмечены и время которых подошло.
const unmarked = computed(() =>
    props.group.doses
        .filter(
            (dose) =>
                dose.due &&
                (dose.status === 'scheduled' || dose.status === 'missed'),
        )
        .map((dose) => dose.id),
);

const form = useForm<{ doses: number[] }>({ doses: [] });

function takeAll(): void {
    form.doses = unmarked.value;
    form.submit(DoseController.takeAll(), { preserveScroll: true });
}
</script>

<template>
    <article
        class="flex scroll-mt-4 flex-col gap-3 rounded-lg border p-4"
        :class="{ 'border-primary ring-2 ring-primary/30': highlighted }"
        data-test="dose-group"
        :data-highlighted="highlighted ? 'true' : undefined"
    >
        <header class="flex items-center justify-between gap-2">
            <h3 class="text-lg font-semibold">{{ group.time }}</h3>
            <Button
                v-if="unmarked.length >= 2"
                size="sm"
                variant="outline"
                :disabled="form.processing"
                data-test="take-all-doses"
                @click="takeAll"
            >
                Принял всё
            </Button>
        </header>

        <template v-for="(dose, index) in group.doses" :key="dose.id">
            <hr v-if="index > 0" />
            <DoseItem
                :dose="dose"
                @mark="(status) => emit('mark', dose, status)"
            />
        </template>
    </article>
</template>
