<script setup lang="ts">
import { ChevronDown, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdherenceDays from '@/components/AdherenceDays.vue';
import { Badge } from '@/components/ui/badge';
import type { AdherenceCourse, RegularPeriod } from '@/types';

const props = defineProps<{
    course: AdherenceCourse;
}>();

// Завершённый курс свёрнут, пока врач его не откроет.
const open = ref(!props.course.finished);

const dates = computed(() =>
    props.course.finished
        ? `Курс ${props.course.started} – ${props.course.ended}`
        : `Курс с ${props.course.started}, день ${props.course.day}`,
);

function share(value: number | null): string {
    return value === null ? '—' : `${value}%`;
}

// Что было с приёмами периода, без нулей: «вовремя 10 · поздно 2 · пропущено 1».
function counts(period: RegularPeriod): string[] {
    const items: [string, number][] = [
        ['вовремя', period.on_time],
        ['поздно', period.late],
        ['пропущено', period.missed],
        ['отмечено позже', period.marked_late],
        ['дней без данных', period.no_data_days],
    ];

    return items
        .filter(([, count]) => count > 0)
        .map(([label, count]) => `${label} ${count}`);
}
</script>

<template>
    <article
        class="flex flex-col gap-4 rounded-lg border p-4 break-words"
        data-test="adherence-course"
    >
        <header class="flex flex-col gap-1">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="font-medium">
                    <button
                        v-if="course.finished"
                        type="button"
                        class="flex items-center gap-1 text-left"
                        :aria-expanded="open"
                        data-test="adherence-course-toggle"
                        @click="open = !open"
                    >
                        {{ course.drug }}
                        <ChevronDown
                            class="size-4 transition-transform"
                            :class="{ 'rotate-180': open }"
                        />
                    </button>
                    <template v-else>{{ course.drug }}</template>
                </h3>
                <Badge v-if="course.kind === 'as_needed'" variant="secondary">
                    {{ course.kind_label }}
                </Badge>
                <Badge v-if="course.source === 'patient'" variant="outline">
                    Добавил пациент
                </Badge>
            </div>
            <p class="text-sm text-muted-foreground">{{ dates }}</p>
        </header>

        <template v-if="open">
            <p
                v-if="course.missing_times"
                class="flex items-start gap-2 rounded-md bg-amber-100 p-2 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
                data-test="adherence-missing-times"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                Пациент не выбрал время приёма, приёмы не планируются.
            </p>

            <ul
                v-if="course.kind === 'regular'"
                class="flex flex-col divide-y rounded-md border"
            >
                <li
                    v-for="period in course.periods"
                    :key="period.days"
                    class="flex flex-col gap-1 p-3 text-sm"
                    data-test="adherence-period"
                >
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-medium">{{ period.days }}</span>
                        <span class="text-muted-foreground">{{
                            period.dates
                        }}</span>
                        <Badge v-if="period.current" variant="outline">
                            идёт
                        </Badge>
                    </div>
                    <p>
                        <span
                            class="text-lg font-semibold"
                            data-test="adherence-share"
                            >{{ share(period.share) }}</span
                        >
                        <span
                            v-if="period.counted > 0"
                            class="text-muted-foreground"
                        >
                            · принято {{ period.taken }} из
                            {{ period.counted }}</span
                        >
                        <span v-else class="text-muted-foreground">
                            · отметок и пропусков ещё нет</span
                        >
                    </p>
                    <p
                        v-if="period.by_time_of_day"
                        data-test="adherence-by-time"
                    >
                        {{
                            period.by_time_of_day
                                .map(
                                    (item) =>
                                        `${item.label} ${share(item.share)}`,
                                )
                                .join(' · ')
                        }}
                    </p>
                    <p
                        v-if="counts(period).length > 0"
                        class="text-muted-foreground"
                    >
                        {{ counts(period).join(' · ') }}
                    </p>
                    <p v-if="period.skipped.length > 0">
                        Не принял:
                        <template
                            v-for="(item, index) in period.skipped"
                            :key="item.label"
                        >
                            <template v-if="index > 0">, </template>
                            <span
                                :class="{
                                    'font-medium text-destructive': item.unwell,
                                }"
                                >{{ item.label.toLowerCase() }} —
                                {{ item.count }}</span
                            >
                        </template>
                    </p>
                </li>
            </ul>

            <ul v-else class="flex flex-col divide-y rounded-md border">
                <li
                    v-for="period in course.periods"
                    :key="period.days"
                    class="flex flex-col gap-1 p-3 text-sm"
                    data-test="adherence-period"
                >
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-medium">{{ period.days }}</span>
                        <span class="text-muted-foreground">{{
                            period.dates
                        }}</span>
                        <Badge v-if="period.current" variant="outline">
                            идёт
                        </Badge>
                    </div>
                    <p>
                        <span
                            >дней с приёмом {{ period.days_with_doses }} · всего
                            приёмов {{ period.doses }}</span
                        >
                        <span
                            v-if="period.days_over_limit > 0"
                            class="font-medium text-destructive"
                        >
                            · дней больше разрешённого
                            {{ period.days_over_limit }}</span
                        >
                    </p>
                </li>
            </ul>

            <AdherenceDays :course="course" />
        </template>
    </article>
</template>
