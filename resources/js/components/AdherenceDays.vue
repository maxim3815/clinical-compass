<script setup lang="ts">
import { computed, ref } from 'vue';
import { plural } from '@/lib/plural';
import type {
    AdherenceCourse,
    AsNeededDay,
    DoseDayResult,
    RegularDay,
} from '@/types';

const props = defineProps<{
    course: AdherenceCourse;
}>();

// Цвет клетки регулярного курса по итогу дня и подписи для легенды.
const results: { result: DoseDayResult; label: string; class: string }[] = [
    {
        result: 'on_time',
        label: 'все вовремя',
        class: 'bg-success text-success-foreground',
    },
    {
        result: 'late',
        label: 'все, часть поздно',
        class: 'bg-success-subtle text-success ring-1 ring-success-border ring-inset',
    },
    {
        result: 'partial',
        label: 'часть не принята',
        class: 'bg-warning-subtle text-warning ring-1 ring-warning-border ring-inset',
    },
    {
        result: 'none',
        label: 'ни один не принят',
        class: 'bg-danger text-danger-foreground',
    },
    {
        result: 'pending',
        label: 'ещё не наступили',
        class: 'border border-dashed border-muted-foreground',
    },
    {
        result: 'no_data',
        label: 'нет данных',
        class: 'bg-muted text-muted-foreground',
    },
];

const resultClass = Object.fromEntries(
    results.map((item) => [item.result, item.class]),
) as Record<DoseDayResult, string>;

const selectedDate = ref<string | null>(null);

const selectedRegular = computed<RegularDay | null>(() =>
    props.course.kind === 'regular'
        ? (props.course.days.find((day) => day.date === selectedDate.value) ??
          null)
        : null,
);

const selectedAsNeeded = computed<AsNeededDay | null>(() =>
    props.course.kind === 'as_needed'
        ? (props.course.days.find((day) => day.date === selectedDate.value) ??
          null)
        : null,
);

function toggle(date: string): void {
    selectedDate.value = selectedDate.value === date ? null : date;
}

function dosesCount(count: number): string {
    return `${count} ${plural(count, ['приём', 'приёма', 'приёмов'])}`;
}

function asNeededLabel(day: AsNeededDay): string {
    return `${day.label}: ${dosesCount(day.count)}${day.over_limit ? ', больше разрешённого' : ''}`;
}

function asNeededClass(day: AsNeededDay): string {
    if (day.over_limit) {
        return 'bg-danger text-danger-foreground';
    }

    return day.count > 0
        ? 'bg-secondary text-secondary-foreground ring-1 ring-primary/20 ring-inset'
        : 'bg-muted text-muted-foreground';
}
</script>

<template>
    <div class="flex flex-col gap-3" data-test="adherence-days">
        <p class="text-sm text-muted-foreground">
            Последние дни курса. Нажмите на день, чтобы увидеть приёмы.
        </p>

        <div
            v-if="course.kind === 'regular'"
            class="grid grid-cols-7 gap-1 sm:grid-cols-14"
        >
            <button
                v-for="day in course.days"
                :key="day.date"
                type="button"
                class="flex h-8 items-center justify-center rounded text-xs font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="[
                    resultClass[day.result],
                    selectedDate === day.date &&
                        'ring-2 ring-foreground ring-offset-1 ring-offset-background',
                ]"
                :aria-label="`${day.label}: ${day.result_label}`"
                :aria-pressed="selectedDate === day.date"
                :data-date="day.date"
                data-test="adherence-day"
                @click="toggle(day.date)"
            >
                {{ day.day_of_month }}
            </button>
        </div>

        <div v-else class="grid grid-cols-7 gap-1 sm:grid-cols-14">
            <button
                v-for="day in course.days"
                :key="day.date"
                type="button"
                class="flex h-8 items-center justify-center rounded text-xs font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="[
                    asNeededClass(day),
                    selectedDate === day.date &&
                        'ring-2 ring-foreground ring-offset-1 ring-offset-background',
                ]"
                :aria-label="asNeededLabel(day)"
                :aria-pressed="selectedDate === day.date"
                :data-date="day.date"
                data-test="adherence-day"
                @click="toggle(day.date)"
            >
                {{ day.count }}
            </button>
        </div>

        <ul
            v-if="course.kind === 'regular'"
            class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            <li
                v-for="item in results"
                :key="item.result"
                class="flex items-center gap-1.5"
            >
                <span class="size-3 rounded-sm" :class="item.class" />
                {{ item.label }}
            </li>
        </ul>
        <p v-else class="text-xs text-muted-foreground">
            В клетке — сколько раз принят препарат за день, красным — больше,
            чем разрешено в сутки.
        </p>

        <div
            v-if="selectedRegular"
            class="flex flex-col gap-2 rounded-md bg-muted/50 p-3 text-sm"
            data-test="adherence-day-details"
        >
            <p class="font-medium">
                {{ selectedRegular.label }}: {{ selectedRegular.result_label }}
            </p>
            <p
                v-if="selectedRegular.doses.length === 0"
                class="text-muted-foreground"
            >
                Приёмы в этот день не планировались: пациент не выбрал время
                приёма.
            </p>
            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="dose in selectedRegular.doses"
                    :key="dose.id"
                    class="break-words"
                    data-test="adherence-dose"
                >
                    <span class="font-medium"
                        >{{ dose.time_of_day }}, {{ dose.time }}:</span
                    >
                    {{ ' ' }}
                    <span
                        :class="{
                            'text-destructive':
                                dose.status === 'skipped' ||
                                dose.status === 'missed',
                            'font-medium': dose.unwell,
                        }"
                        >{{ dose.status_text }}</span
                    >
                    <span
                        v-if="dose.skip_comment"
                        class="block text-muted-foreground"
                    >
                        {{ dose.skip_comment }}
                    </span>
                    <span
                        v-if="dose.marked_late"
                        class="block text-xs text-muted-foreground"
                    >
                        {{
                            dose.marked_at
                                ? `Отмечено позже, ${dose.marked_at}`
                                : 'Отмечено позже'
                        }}
                    </span>
                </li>
            </ul>
        </div>

        <div
            v-if="selectedAsNeeded"
            class="rounded-md bg-muted/50 p-3 text-sm"
            data-test="adherence-day-details"
        >
            <p class="font-medium">{{ asNeededLabel(selectedAsNeeded) }}</p>
            <p v-if="selectedAsNeeded.times.length > 0">
                {{ selectedAsNeeded.times.join(', ') }}
            </p>
        </div>
    </div>
</template>
