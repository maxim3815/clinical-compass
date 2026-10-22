<script setup lang="ts">
import { ChevronDown, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdherenceDays from '@/components/AdherenceDays.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { plural } from '@/lib/plural';
import type { AdherenceCourse, RegularPeriod } from '@/types';

const props = withDefaults(
    defineProps<{
        course: AdherenceCourse;
        // Показать легенду цветов дней: одна на раздел.
        legend?: boolean;
    }>(),
    { legend: true },
);

// Завершённый курс свёрнут, пока врач его не откроет.
const open = ref(!props.course.finished);

const dates = computed(() =>
    props.course.finished
        ? `Курс ${props.course.started} – ${props.course.ended}`
        : `Курс с ${props.course.started}, день ${props.course.day}`,
);

// Периоды копятся весь курс: видны текущий и предыдущий, остальные — по кнопке. Период,
// где пациенту было плохо или были побочные эффекты, виден всегда. Одну строку не прячем:
// кнопка заняла бы столько же места.
const VISIBLE_PERIODS = 2;
const allPeriods = ref(false);

function keepsVisible(
    period: AdherenceCourse['periods'][number],
    index: number,
) {
    return (
        index < VISIBLE_PERIODS ||
        ('skipped' in period && period.skipped.some((item) => item.unwell))
    );
}

const hiddenPeriods = computed(
    () =>
        props.course.periods.filter(
            (period, index) => !keepsVisible(period, index),
        ).length,
);

function isShown(period: AdherenceCourse['periods'][number], index: number) {
    return (
        allPeriods.value ||
        hiddenPeriods.value < 2 ||
        keepsVisible(period, index)
    );
}

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
        class="@container flex flex-col gap-4 rounded-xl border bg-card p-4 break-words shadow-xs"
        data-test="adherence-course"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1"
        >
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="font-semibold">
                    <button
                        v-if="course.finished"
                        type="button"
                        class="flex items-center gap-1 rounded-sm text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
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
                class="flex items-start gap-2 rounded-lg border border-warning-border bg-warning-subtle p-2 text-sm text-foreground"
                data-test="adherence-missing-times"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0 text-warning" />
                Пациент не выбрал время приёма, приёмы не планируются.
            </p>

            <!-- Периоды строками: период, доля, подробности. На узком месте — друг под другом. -->
            <ul
                v-if="course.kind === 'regular'"
                class="flex flex-col divide-y rounded-lg border text-sm"
                aria-label="Контрольные периоды"
            >
                <li
                    v-for="period in course.periods.filter(isShown)"
                    :key="period.days"
                    class="grid gap-x-4 gap-y-1 p-3 @lg:grid-cols-[9rem_8rem_minmax(0,1fr)]"
                    :class="{ 'bg-accent/40': period.current }"
                    data-test="adherence-period"
                >
                    <div>
                        <p class="flex flex-wrap items-center gap-x-2">
                            <span class="font-medium">{{ period.days }}</span>
                            <Badge v-if="period.current" variant="outline">
                                идёт
                            </Badge>
                        </p>
                        <p class="text-muted-foreground">{{ period.dates }}</p>
                    </div>
                    <p class="flex items-baseline gap-2 @lg:flex-col @lg:gap-0">
                        <span
                            class="text-lg font-semibold tabular-nums"
                            data-test="adherence-share"
                            >{{ share(period.share) }}</span
                        >
                        <span
                            v-if="period.counted > 0"
                            class="text-muted-foreground"
                            >принято {{ period.taken }} из
                            {{ period.counted }}</span
                        >
                    </p>
                    <div class="flex flex-col gap-0.5">
                        <p
                            v-if="period.counted === 0"
                            class="text-muted-foreground"
                        >
                            Отметок и пропусков ещё нет
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
                                        'font-medium text-warning': item.unwell,
                                    }"
                                    >{{ item.label.toLowerCase() }} —
                                    {{ item.count }}</span
                                >
                            </template>
                        </p>
                    </div>
                </li>
            </ul>

            <ul
                v-else
                class="flex flex-col divide-y rounded-lg border text-sm"
                aria-label="Контрольные периоды"
            >
                <li
                    v-for="period in course.periods.filter(isShown)"
                    :key="period.days"
                    class="grid gap-x-4 gap-y-1 p-3 @lg:grid-cols-[9rem_minmax(0,1fr)]"
                    :class="{ 'bg-accent/40': period.current }"
                    data-test="adherence-period"
                >
                    <div>
                        <p class="flex flex-wrap items-center gap-x-2">
                            <span class="font-medium">{{ period.days }}</span>
                            <Badge v-if="period.current" variant="outline">
                                идёт
                            </Badge>
                        </p>
                        <p class="text-muted-foreground">{{ period.dates }}</p>
                    </div>
                    <p>
                        дней с приёмом {{ period.days_with_doses }} · всего
                        приёмов {{ period.doses
                        }}<span
                            v-if="period.days_over_limit > 0"
                            class="font-medium text-warning"
                        >
                            · дней больше разрешённого
                            {{ period.days_over_limit }}</span
                        >
                    </p>
                </li>
            </ul>

            <Button
                v-if="hiddenPeriods >= 2"
                type="button"
                variant="ghost"
                size="sm"
                class="-mt-2 self-start text-primary"
                :aria-expanded="allPeriods"
                data-test="adherence-periods-toggle"
                @click="allPeriods = !allPeriods"
            >
                {{
                    allPeriods
                        ? 'Свернуть периоды'
                        : `Ещё ${hiddenPeriods} ${plural(hiddenPeriods, ['период', 'периода', 'периодов'])}`
                }}
                <ChevronDown
                    class="transition-transform"
                    :class="{ 'rotate-180': allPeriods }"
                />
            </Button>

            <AdherenceDays :course="course" :legend="legend" />
        </template>
    </article>
</template>
