<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CircleCheck, Clock } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import DoseController from '@/actions/App/Http/Controllers/Patient/DoseController';
import DoseItem from '@/components/DoseItem.vue';
import MoodPicker from '@/components/MoodPicker.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useDoseWords } from '@/composables/useDoseWords';
import type { Dose, DoseGroup } from '@/types';

const props = defineProps<{
    group: DoseGroup;
    // Вчера или сегодня: время группы озвучивается с днём.
    day: 'today' | 'yesterday';
    // Пациент пришёл из напоминания об этих приёмах.
    highlighted?: boolean;
    // Лица настроения: только у пациента психиатра, смотреть их больше некому.
    moodEnabled?: boolean;
}>();

const emit = defineEmits<{
    mark: [dose: Dose, status: 'taken' | 'skipped'];
}>();

const words = useDoseWords();
const timeId = computed(() => `doses-${props.day}-${props.group.time}`);

// Приёмы, которые пора принять: время подошло, а окно ещё открыто. Пропущенные сюда не входят:
// их отмечают с указанием времени.
const takeable = computed(() =>
    props.group.doses
        .filter((dose) => dose.due && dose.status === 'scheduled')
        .map((dose) => dose.id),
);

// У группы из одного приёма статус уже виден у самого приёма.
const allMarked = computed(
    () =>
        props.group.doses.length > 1 &&
        props.group.doses.every(
            (dose) => dose.status === 'taken' || dose.status === 'skipped',
        ),
);

// После отметки «Принял» или «Не принял» сегодня — три лица настроения, по желанию. Отмеченные
// кнопкой в пуше тоже: лица ждут на карточке до конца дня. Пока в группе есть неотмеченный
// приём, лиц нет: на карточке одно главное действие.
const moodShown = computed(
    () =>
        props.day === 'today' &&
        props.moodEnabled &&
        !props.group.doses.some((dose) => dose.status === 'scheduled') &&
        props.group.doses.some(
            (dose) => dose.status === 'taken' || dose.status === 'skipped',
        ),
);

// Приёмы одной группы назначены на одно время, поэтому и отмечать их можно с одного момента.
const notDueYet = computed(() =>
    props.group.doses.find((dose) => !dose.due && dose.status === 'scheduled'),
);

// Два и больше приёма, которые пора принять, отмечаются одной кнопкой «Принял всё». Кнопки
// каждого приёма («Принял», «Не принял», другое время) открываются по просьбе: так на первом
// экране одно главное действие.
const separately = ref(false);
const card = useTemplateRef<HTMLElement>('card');

function doseActions(dose: Dose): 'primary' | 'secondary' | 'none' {
    if (takeable.value.length === 1) {
        return 'primary';
    }

    return takeable.value.includes(dose.id) && !separately.value
        ? 'none'
        : 'secondary';
}

// Кнопка пропадает: фокус переходит к первому приёму.
async function showSeparately(): Promise<void> {
    separately.value = true;
    await nextTick();
    card.value?.querySelector<HTMLElement>('[data-test="take-dose"]')?.focus();
}

const form = useForm<{ doses: number[] }>({ doses: [] });

function takeAll(): void {
    form.doses = takeable.value;
    form.submit(DoseController.takeAll(), {
        preserveScroll: true,
        onSuccess: async () => {
            await nextTick();
            card.value
                ?.querySelector<HTMLElement>('[data-test="dose-status"]')
                ?.focus();
        },
    });
}
</script>

<template>
    <article
        ref="card"
        class="flex scroll-mt-20 flex-col rounded-2xl border bg-card shadow-xs"
        :class="{
            'border-primary ring-2 ring-primary/30': highlighted,
            'border-primary/50': !highlighted && takeable.length > 0,
        }"
        data-test="dose-group"
        :data-highlighted="highlighted ? 'true' : undefined"
    >
        <header class="flex items-center justify-between gap-3 px-4 pt-4">
            <h3 :id="timeId" class="text-2xl font-semibold tabular-nums">
                <span class="sr-only">{{
                    day === 'today' ? 'Сегодня в' : 'Вчера в'
                }}</span>
                {{ group.time }}
            </h3>
            <span
                v-if="takeable.length > 0"
                class="rounded-full bg-secondary px-3 py-1 font-medium text-secondary-foreground"
            >
                Пора принять
            </span>
            <span
                v-else-if="allMarked"
                class="flex items-center gap-1.5 font-medium text-success"
            >
                <CircleCheck class="size-5" />
                Отмечено
            </span>
        </header>

        <ul class="divide-y">
            <li v-for="dose in group.doses" :key="dose.id" class="p-4">
                <DoseItem
                    :dose="dose"
                    :actions="doseActions(dose)"
                    :time-id="timeId"
                    @mark="(status) => emit('mark', dose, status)"
                />
            </li>
        </ul>

        <div
            v-if="takeable.length >= 2 && !separately"
            class="flex flex-col gap-1 px-4 pb-4"
        >
            <Button
                size="xl"
                class="w-full"
                :disabled="form.processing"
                :aria-busy="form.processing"
                :aria-describedby="timeId"
                data-test="take-all-doses"
                @click="takeAll"
            >
                <Spinner v-if="form.processing" class="size-5" />
                <CircleCheck v-else />
                {{ words.tookAll }}
            </Button>
            <Button
                size="lg"
                variant="ghost"
                class="self-center px-3 text-primary"
                data-test="mark-separately"
                @click="showSeparately"
            >
                {{ words.notAll }}
            </Button>
        </div>

        <p
            v-if="notDueYet"
            class="flex items-center gap-2 px-4 pb-4 text-muted-foreground"
        >
            <Clock class="size-5 shrink-0" />
            Отметить приём можно с {{ notDueYet.due_from }}.
        </p>

        <MoodPicker
            v-if="moodShown"
            :slot="group.time"
            :mood="group.mood"
            :time-id="timeId"
        />
    </article>
</template>
