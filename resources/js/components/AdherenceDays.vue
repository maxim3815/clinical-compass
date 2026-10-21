<script setup lang="ts">
import { computed, ref } from 'vue';
import { plural } from '@/lib/plural';
import { cn } from '@/lib/utils';
import type {
    AdherenceCourse,
    AsNeededDay,
    DoseDayResult,
    RegularDay,
} from '@/types';

const props = defineProps<{
    course: AdherenceCourse;
}>();

// Цвет клетки регулярного курса по итогу дня и подписи для легенды. Непринятое отмечено
// ещё и знаком: точка — часть не принята, черта — ни один. Различие видно и без цвета.
const results: {
    result: DoseDayResult;
    label: string;
    class: string;
    mark?: 'dot' | 'bar';
}[] = [
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
        mark: 'dot',
    },
    {
        result: 'none',
        label: 'ни один не принят',
        class: 'bg-danger text-danger-foreground',
        mark: 'bar',
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

const resultMark = Object.fromEntries(
    results.map((item) => [item.result, item.mark]),
) as Record<DoseDayResult, 'dot' | 'bar' | undefined>;

// Выбранный день — рамкой внутри клетки, фокус — обводкой снаружи: их не спутать.
const SELECTED = 'ring-2 ring-inset ring-foreground';

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
                class="relative flex h-9 items-center justify-center rounded-md text-sm font-medium tabular-nums outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid pointer-coarse:h-11"
                :class="
                    cn(
                        resultClass[day.result],
                        selectedDate === day.date && SELECTED,
                    )
                "
                :title="day.label"
                :aria-label="`${day.label}: ${day.result_label}`"
                :aria-pressed="selectedDate === day.date"
                :data-date="day.date"
                data-test="adherence-day"
                @click="toggle(day.date)"
            >
                {{ day.day_of_month }}
                <span
                    v-if="resultMark[day.result] === 'dot'"
                    class="absolute top-1 right-1 size-1.5 rounded-full bg-current"
                    aria-hidden="true"
                />
                <span
                    v-else-if="resultMark[day.result] === 'bar'"
                    class="absolute inset-x-2 bottom-1 h-0.5 rounded-full bg-current"
                    aria-hidden="true"
                />
            </button>
        </div>

        <div v-else class="grid grid-cols-7 gap-1 sm:grid-cols-14">
            <button
                v-for="day in course.days"
                :key="day.date"
                type="button"
                class="flex h-9 items-center justify-center rounded-md text-sm font-medium tabular-nums outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid pointer-coarse:h-11"
                :class="
                    cn(
                        asNeededClass(day),
                        selectedDate === day.date && SELECTED,
                    )
                "
                :title="day.label"
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
            class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground"
        >
            <li
                v-for="item in results"
                :key="item.result"
                class="flex items-center gap-1.5"
            >
                <span
                    class="relative size-4 shrink-0 rounded-sm"
                    :class="item.class"
                >
                    <span
                        v-if="item.mark === 'dot'"
                        class="absolute top-0.5 right-0.5 size-1 rounded-full bg-current"
                    />
                    <span
                        v-else-if="item.mark === 'bar'"
                        class="absolute inset-x-0.5 bottom-0.5 h-0.5 rounded-full bg-current"
                    />
                </span>
                {{ item.label }}
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">
            В клетке — сколько раз принят препарат за день, красным — больше,
            чем разрешено в сутки.
        </p>

        <div
            v-if="selectedRegular"
            class="flex flex-col gap-2 rounded-lg bg-muted/60 p-3 text-sm"
            aria-live="polite"
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
                        class="block text-muted-foreground"
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
            class="rounded-lg bg-muted/60 p-3 text-sm"
            aria-live="polite"
            data-test="adherence-day-details"
        >
            <p class="font-medium">{{ asNeededLabel(selectedAsNeeded) }}</p>
            <p v-if="selectedAsNeeded.times.length > 0">
                {{ selectedAsNeeded.times.join(', ') }}
            </p>
        </div>
    </div>
</template>
