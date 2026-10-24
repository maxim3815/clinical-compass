<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    CalendarCheck,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    CircleDashed,
    CircleMinus,
    Clock,
    MessageCircle,
    Pill,
} from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { plural } from '@/lib/plural';
import { cn } from '@/lib/utils';
import { calendar as calendarRoute, home } from '@/routes/patient';
import { show as chat } from '@/routes/patient/chat';
import type {
    CalendarDay,
    CalendarDayResult,
    CalendarDose,
    IntakeCalendarMonth,
} from '@/types';

const props = defineProps<{
    calendar: IntakeCalendarMonth;
    // Есть ли у пациента хоть один приём: иначе вместо календаря объяснение.
    hasHistory: boolean;
    hasDoctor: boolean;
}>();

const page = usePage();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Сегодня',
                href: home(),
            },
            {
                title: 'Календарь приёма',
                href: calendarRoute(),
            },
        ],
    },
});

const WEEKDAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

// Итог дня: подпись, цвет клетки и знак. Непринятое отмечено ещё и знаком — точка, когда
// принято не всё, черта, когда не принято ничего: различие видно и без цвета. Красного нет:
// пропуск — не опасность. «Не отмечено» в подписи: пациент мог принять и забыть отметить.
const RESULTS: {
    result: CalendarDayResult;
    label: string;
    class: string;
    mark?: 'dot' | 'bar';
}[] = [
    {
        result: 'taken',
        label: 'Всё принято',
        class: 'bg-success text-success-foreground',
    },
    {
        result: 'partial',
        label: 'Принято не всё',
        class: 'bg-warning-subtle ring-1 ring-warning-border ring-inset',
        mark: 'dot',
    },
    {
        result: 'none',
        label: 'Не принято или не отмечено',
        class: 'bg-danger-subtle ring-1 ring-danger-border ring-inset',
        mark: 'bar',
    },
    {
        result: 'pending',
        label: 'День ещё идёт',
        class: 'border-2 border-dashed border-muted-foreground',
    },
    {
        result: 'no_data',
        label: 'Нет приёмов по расписанию',
        class: 'text-muted-foreground ring-1 ring-border ring-inset',
    },
];

const byResult = Object.fromEntries(
    RESULTS.map((item) => [item.result, item]),
) as Record<CalendarDayResult, (typeof RESULTS)[number]>;

const LEGEND_LABELS: Partial<Record<CalendarDayResult, string>> = {
    no_data: 'Нет приёмов по расписанию',
};

// Выбранный день — двойной рамкой внутри клетки (тёмная и светлая), её видно на любом
// цвете. Сегодня — кругом вокруг числа, фокус — обводкой снаружи: их не спутать.
const SELECTED =
    'ring-2 ring-inset ring-foreground after:pointer-events-none after:absolute after:inset-0.5 after:rounded-md after:ring-2 after:ring-background after:ring-inset';

// В текущем месяце сразу открыт сегодняшний день, в прошлых — ничего.
const selectedDate = ref<string | null>(null);
// День, на который ведёт Tab: по сетке ходят стрелками, как в календаре.
const focusDate = ref<string | null>(null);
// Короткое сообщение скринридеру о смене месяца и выбранном дне.
const announcement = ref('');

const heading = useTemplateRef<HTMLElement>('heading');
const details = useTemplateRef<HTMLElement>('details');

const pastDays = computed(() =>
    props.calendar.days.filter((day) => !day.future),
);

const selected = computed<CalendarDay | null>(
    () =>
        props.calendar.days.find((day) => day.date === selectedDate.value) ??
        null,
);

const tabDate = computed(
    () =>
        focusDate.value ??
        selectedDate.value ??
        pastDays.value[pastDays.value.length - 1]?.date,
);

const summary = computed(() => {
    const { counted, taken, in_month: inMonth, next } = props.calendar;

    if (counted > 0) {
        // «Принято» — по отметкам, как в статусах приёмов: без «вы», итог не упрекает.
        return `В ${inMonth} принято ${taken} из ${counted} ${plural(counted, ['приёма', 'приёмов', 'приёмов'])} по расписанию.`;
    }

    // Следующего месяца нет — это текущий.
    return next === null
        ? 'В этом месяце отмеченных приёмов пока нет.'
        : `В ${inMonth} приёмов по расписанию не было.`;
});

watch(
    () => props.calendar.month,
    async (month, previous) => {
        selectedDate.value =
            props.calendar.days.find((day) => day.today)?.date ?? null;
        focusDate.value = null;

        if (previous === undefined) {
            return;
        }

        announcement.value = [props.calendar.title, summary.value]
            .filter(Boolean)
            .join('. ');

        // Стрелка, которую нажали, исчезает на крайнем месяце: фокус — на заголовок месяца.
        await nextTick();

        if (
            document.activeElement === document.body ||
            document.activeElement === null
        ) {
            heading.value?.focus();
        }
    },
    { immediate: true },
);

async function select(day: CalendarDay): Promise<void> {
    selectedDate.value = day.date;
    focusDate.value = day.date;
    announcement.value = `${dayLabel(day)}. Подробности под календарём.`;

    // Состав дня под сеткой: если он ниже середины экрана, подтягиваем его наверх.
    await nextTick();
    const top = details.value?.getBoundingClientRect().top;

    if (top !== undefined && (top < 0 || top > window.innerHeight * 0.55)) {
        details.value?.scrollIntoView({ block: 'start' });
    }
}

const KEY_STEPS: Record<string, number> = {
    ArrowLeft: -1,
    ArrowRight: 1,
    ArrowUp: -7,
    ArrowDown: 7,
};

function move(day: CalendarDay, event: KeyboardEvent): void {
    const days = pastDays.value;
    const index = days.findIndex((item) => item.date === day.date);
    let target: number;

    if (event.key in KEY_STEPS) {
        target = index + KEY_STEPS[event.key];
    } else if (event.key === 'Home') {
        target = 0;
    } else if (event.key === 'End') {
        target = days.length - 1;
    } else {
        return;
    }

    event.preventDefault();
    const date = days[Math.min(Math.max(target, 0), days.length - 1)].date;
    focusDate.value = date;
    void nextTick(() =>
        document
            .querySelector<HTMLElement>(
                `[data-test="calendar-day"][data-date="${date}"]`,
            )
            ?.focus(),
    );
}

function dateTitle(day: CalendarDay): string {
    if (day.today || day.yesterday) {
        return `${day.today ? 'Сегодня' : 'Вчера'}, ${day.label.toLowerCase()}`;
    }

    return day.label;
}

// «Отметить» ведёт на «Сегодня» к карточкам неотмеченных приёмов дня: они подсвечены и на
// экране, вчерашние не придётся искать под сегодняшними.
function markHref(day: CalendarDay) {
    const doses = day.doses
        .filter(
            (dose) => dose.status === 'scheduled' || dose.status === 'missed',
        )
        .map((dose) => dose.id)
        .join(',');

    return home({ query: { doses } });
}

// Подпись клетки для скринридера: «Пятница, 23 октября: принято не всё, был приём «по необходимости»».
function dayLabel(day: CalendarDay): string {
    const facts: string[] = [];

    if (day.result) {
        facts.push(byResult[day.result].label.toLowerCase());
    }

    if (day.as_needed.length > 0) {
        facts.push('был приём «по необходимости»');
    }

    return facts.length > 0
        ? `${dateTitle(day)}: ${facts.join(', ')}`
        : dateTitle(day);
}

function monthHref(month: string) {
    return calendarRoute({ query: { month } });
}

// Пропущенный приём старше вчерашнего уже не отметить: объясняем, почему нет кнопки.
const markClosed = computed(
    () =>
        selected.value !== null &&
        !selected.value.can_mark &&
        selected.value.doses.some(
            (dose) => dose.status === 'missed' || dose.status === 'scheduled',
        ),
);

function times(count: number): string {
    return `${count} ${plural(count, ['раз', 'раза', 'раз'])}`;
}

function doseIcon(dose: CalendarDose) {
    switch (dose.status) {
        case 'taken':
            return CircleCheck;
        case 'skipped':
            return CircleMinus;
        case 'missed':
            return CircleDashed;
        default:
            return Clock;
    }
}
</script>

<template>
    <Head title="Календарь приёма" />

    <!--
        Как прошёл приём по дням: цвет дня — итог всех плановых приёмов, состав дня — по
        нажатию. Только просмотр: отметить приём можно на «Сегодня», за сегодня и вчера.
    -->
    <div class="flex flex-col gap-6 p-4">
        <h1 class="text-2xl font-semibold tracking-tight">Календарь приёма</h1>

        <p
            v-if="!hasHistory"
            class="rounded-2xl border border-dashed p-4 text-muted-foreground"
            data-test="calendar-empty"
        >
            Здесь будет видно, как вы принимали препараты по дням. Календарь
            заполнится, когда начнутся приёмы по расписанию.
        </p>

        <section
            v-else
            class="flex flex-col gap-4"
            aria-labelledby="calendar-month"
            data-test="intake-calendar"
        >
            <p class="sr-only" aria-live="polite" data-test="calendar-live">
                {{ announcement }}
            </p>

            <div class="flex flex-col gap-1">
                <h2
                    id="calendar-month"
                    ref="heading"
                    tabindex="-1"
                    class="text-xl font-semibold outline-none"
                    data-test="calendar-title"
                >
                    {{ calendar.title }}
                </h2>
                <p
                    :class="{ 'text-muted-foreground': calendar.counted === 0 }"
                    data-test="calendar-summary"
                >
                    {{ summary }}
                </p>
            </div>

            <!-- Подписи стрелок — месяцы: видно, куда ведёт стрелка. -->
            <div
                v-if="calendar.previous || calendar.next"
                class="flex items-center justify-between gap-2"
            >
                <Button
                    v-if="calendar.previous"
                    variant="outline"
                    size="lg"
                    class="-ml-1"
                    as-child
                >
                    <Link
                        :href="monthHref(calendar.previous)"
                        replace
                        preserve-state
                        preserve-scroll
                        data-test="calendar-previous"
                    >
                        <ChevronLeft />
                        {{ calendar.previous_title }}
                    </Link>
                </Button>
                <span v-else />

                <Button
                    v-if="calendar.next"
                    variant="outline"
                    size="lg"
                    class="-mr-1"
                    as-child
                >
                    <Link
                        :href="monthHref(calendar.next)"
                        replace
                        preserve-state
                        preserve-scroll
                        data-test="calendar-next"
                    >
                        {{ calendar.next_title }}
                        <ChevronRight />
                    </Link>
                </Button>
            </div>

            <!--
                Клетка не меньше 44 px и на экране 360 px: сетка без своих полей, зазор 2 px.
                Tab ведёт в сетку один раз, дальше по дням — стрелками.
            -->
            <div class="grid grid-cols-7 gap-0.5 sm:gap-1">
                <span
                    v-for="weekday in WEEKDAYS"
                    :key="weekday"
                    class="pb-1 text-center text-muted-foreground"
                    aria-hidden="true"
                >
                    {{ weekday }}
                </span>

                <span
                    v-for="n in calendar.offset"
                    :key="`offset-${n}`"
                    aria-hidden="true"
                />

                <template v-for="day in calendar.days" :key="day.date">
                    <span
                        v-if="day.future"
                        class="flex h-11 items-center justify-center text-muted-foreground tabular-nums"
                        aria-hidden="true"
                    >
                        {{ day.day }}
                    </span>
                    <button
                        v-else
                        type="button"
                        class="relative flex h-11 items-center justify-center rounded-lg font-medium tabular-nums outline-none focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                        :class="
                            cn(
                                day.result && byResult[day.result].class,
                                selectedDate === day.date && SELECTED,
                            )
                        "
                        :tabindex="day.date === tabDate ? 0 : -1"
                        :aria-label="dayLabel(day)"
                        :aria-current="day.today ? 'date' : undefined"
                        :aria-expanded="selectedDate === day.date"
                        aria-controls="calendar-day-details"
                        :data-date="day.date"
                        :data-result="day.result"
                        data-test="calendar-day"
                        @click="select(day)"
                        @keydown="move(day, $event)"
                    >
                        <!--
                            Со значком «по необходимости» число сдвинуто вправо, значок — в самом углу
                            (у таблетки пустые углы): на 360 px они не задевают ни число, ни круг «сегодня».
                        -->
                        <span
                            :class="
                                cn(
                                    day.today &&
                                        'grid size-7 place-items-center rounded-full font-bold ring-2 ring-current',
                                    day.as_needed.length > 0 && 'translate-x-1',
                                )
                            "
                        >
                            {{ day.day }}
                        </span>
                        <Pill
                            v-if="day.as_needed.length > 0"
                            class="absolute top-0 left-0 z-10 size-3.5"
                            aria-hidden="true"
                        />
                        <span
                            v-if="
                                day.result &&
                                byResult[day.result].mark === 'dot'
                            "
                            class="absolute top-1.5 right-1.5 size-2 rounded-full bg-warning"
                            aria-hidden="true"
                        />
                        <span
                            v-else-if="
                                day.result &&
                                byResult[day.result].mark === 'bar'
                            "
                            class="absolute inset-x-3 bottom-1 h-1 rounded-full bg-danger"
                            aria-hidden="true"
                        />
                    </button>
                </template>
            </div>

            <!-- Легенда сразу под сеткой: цвета не надо запоминать. -->
            <ul
                class="flex flex-wrap gap-x-4 gap-y-2 text-muted-foreground"
                aria-label="Что значат цвета"
                data-test="calendar-legend"
            >
                <li
                    v-for="item in RESULTS"
                    :key="item.result"
                    class="flex items-center gap-2"
                >
                    <span
                        class="relative size-6 shrink-0 rounded-md"
                        :class="item.class"
                        aria-hidden="true"
                    >
                        <span
                            v-if="item.mark === 'dot'"
                            class="absolute top-1 right-1 size-2 rounded-full bg-warning"
                        />
                        <span
                            v-else-if="item.mark === 'bar'"
                            class="absolute inset-x-1 bottom-1 h-1 rounded-full bg-danger"
                        />
                    </span>
                    {{ LEGEND_LABELS[item.result] ?? item.label }}
                </li>
                <li class="flex items-center gap-2">
                    <span
                        class="flex size-6 shrink-0 items-center justify-center"
                        aria-hidden="true"
                    >
                        <Pill class="size-4" />
                    </span>
                    По необходимости
                </li>
            </ul>

            <div
                v-if="selected"
                id="calendar-day-details"
                ref="details"
                class="flex scroll-mt-20 flex-col gap-4 rounded-2xl border bg-card p-4 shadow-xs"
                data-test="calendar-day-details"
            >
                <div class="flex flex-col gap-1">
                    <h3 class="text-lg font-semibold">
                        {{ dateTitle(selected) }}
                    </h3>
                    <p v-if="selected.result" class="text-muted-foreground">
                        {{ byResult[selected.result].label }}
                    </p>
                </div>

                <Button
                    v-if="selected.can_mark"
                    size="lg"
                    class="self-start"
                    as-child
                >
                    <Link :href="markHref(selected)" data-test="calendar-mark">
                        <CalendarCheck />
                        {{
                            selected.today
                                ? 'Отметить приём'
                                : 'Отметить вчерашний приём'
                        }}
                    </Link>
                </Button>

                <ul
                    v-if="selected.doses.length > 0"
                    class="flex flex-col gap-3"
                >
                    <li
                        v-for="dose in selected.doses"
                        :key="dose.id"
                        class="flex gap-3"
                        data-test="calendar-dose"
                    >
                        <span class="w-12 shrink-0 font-semibold tabular-nums">
                            {{ dose.time }}
                        </span>
                        <div class="flex min-w-0 flex-col">
                            <span class="font-medium break-words">
                                {{ `${dose.drug}, ${dose.dose}` }}
                            </span>
                            <span
                                class="flex items-start gap-1.5"
                                :class="
                                    dose.status === 'taken'
                                        ? 'text-success'
                                        : 'text-muted-foreground'
                                "
                            >
                                <!-- Значок у первой строки, если статус переносится. -->
                                <component
                                    :is="doseIcon(dose)"
                                    class="mt-1 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ dose.status_text }}
                            </span>
                            <span
                                v-if="dose.skip_comment"
                                class="break-words text-muted-foreground"
                            >
                                {{ `Ваш комментарий: ${dose.skip_comment}` }}
                            </span>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-muted-foreground">
                    Приёмов по расписанию в этот день не было.
                </p>

                <p
                    v-if="markClosed"
                    class="text-muted-foreground"
                    data-test="calendar-mark-closed"
                >
                    Отметить приём можно только за сегодня и вчера.
                </p>

                <div
                    v-if="selected.as_needed.length > 0"
                    class="flex flex-col gap-2"
                    data-test="calendar-as-needed"
                >
                    <h4 class="font-semibold">По необходимости</h4>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="item in selected.as_needed"
                            :key="item.drug"
                            class="flex flex-col"
                        >
                            <span class="break-words">
                                {{
                                    `${item.drug}: ${times(item.count)}, ${item.times.join(', ')}`
                                }}
                            </span>
                            <span
                                v-if="item.over_limit"
                                class="text-muted-foreground"
                                data-test="calendar-over-limit"
                            >
                                {{
                                    `По назначению можно не больше ${item.over_limit} ${plural(item.over_limit, ['раза', 'раз', 'раз'])} в сутки.`
                                }}
                            </span>
                        </li>
                    </ul>
                </div>

                <div
                    v-if="
                        selected.unwell &&
                        hasDoctor &&
                        !page.props.withTherapist
                    "
                    class="flex flex-col items-start gap-2 rounded-xl bg-secondary p-3 text-secondary-foreground"
                    data-test="calendar-unwell"
                >
                    <p>Если самочувствие беспокоит, напишите врачу.</p>
                    <Button variant="outline" size="lg" as-child>
                        <Link :href="chat()">
                            <MessageCircle />
                            Написать врачу
                        </Link>
                    </Button>
                </div>
            </div>

            <p
                v-else
                id="calendar-day-details"
                class="rounded-2xl border border-dashed p-4 text-muted-foreground"
                data-test="calendar-day-hint"
            >
                Нажмите на день, чтобы увидеть приёмы.
            </p>
        </section>
    </div>
</template>
