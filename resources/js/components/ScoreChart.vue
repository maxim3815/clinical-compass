<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import { cn } from '@/lib/utils';
import type { ScorePoint } from '@/types';

/**
 * График сумм опросника по датам: одна линия, точки по датам заполнения, ось от 0 до наибольшей
 * суммы. Линию и сетку рисует SVG, растянутый по области, а точки и подписи — HTML поверх него
 * в процентах: так график тянется по ширине, а текст и точки не искажаются. Значение точки —
 * по наведению, касанию и стрелкам с клавиатуры; для скринридера — таблица.
 */
const props = withDefaults(
    defineProps<{
        points: ScorePoint[];
        max: number;
        // Что на графике, для скринридера: «Беспокойство, сумма баллов».
        label: string;
        // Линии сетки, кроме 0 и наибольшей суммы. У врача — границы степеней.
        ticks?: number[];
        // Текст 16 px — у пациента.
        large?: boolean;
    }>(),
    {
        ticks: () => [],
        large: false,
    },
);

const DAY = 86_400_000;

function time(iso: string): number {
    const [year, month, day] = iso.split('-').map(Number);

    return Date.UTC(year, month - 1, day);
}

// Положение по горизонтали — по датам: сроки календаря идут неравномерно (7, 14, 28 дней).
const positioned = computed(() => {
    const times = props.points.map((point) => time(point.iso));
    const first = Math.min(...times);
    const span = Math.max(...times) - first;

    return props.points.map((point, index) => ({
        ...point,
        x:
            span < DAY
                ? props.points.length === 1
                    ? 50
                    : (index / (props.points.length - 1)) * 100
                : ((times[index] - first) / span) * 100,
        y: 100 - (point.total / props.max) * 100,
    }));
});

const gridLines = computed(() =>
    [...new Set([0, ...props.ticks, props.max])]
        .filter((value) => value >= 0 && value <= props.max)
        .sort((a, b) => a - b),
);

const path = computed(() =>
    positioned.value
        .map(
            (point, index) => `${index === 0 ? 'M' : 'L'}${point.x} ${point.y}`,
        )
        .join(' '),
);

const last = computed(() => positioned.value.at(-1));

// Подписи оси: первая и последняя даты, остальное — по наведению и в таблице.
const axisLabels = computed(() => {
    const points = positioned.value;

    if (points.length === 0) {
        return [];
    }

    return points.length === 1
        ? [{ ...points[0], align: 'center' as const }]
        : [
              { ...points[0], align: 'start' as const },
              { ...points.at(-1)!, align: 'end' as const },
          ];
});

// Точка, значение которой показано.
const active = ref<number | null>(null);
const activePoint = computed(() =>
    active.value === null ? null : positioned.value[active.value],
);

const plot = useTemplateRef<HTMLElement>('plot');

// Целиться в точку не нужно: показывается ближайшая к указателю по горизонтали.
function onPointer(event: PointerEvent): void {
    const box = plot.value?.getBoundingClientRect();

    if (!box || box.width === 0 || positioned.value.length === 0) {
        return;
    }

    const x = ((event.clientX - box.left) / box.width) * 100;
    let nearest = 0;

    positioned.value.forEach((point, index) => {
        if (Math.abs(point.x - x) < Math.abs(positioned.value[nearest].x - x)) {
            nearest = index;
        }
    });

    active.value = nearest;
}

// Мышью значение видно, пока указатель над графиком. Касанием — до касания другой точки
// или места вне графика: после касания браузер сразу присылает pointerleave.
function onPointerLeave(event: PointerEvent): void {
    if (event.pointerType === 'mouse') {
        active.value = null;
    }
}

const chart = useTemplateRef<HTMLElement>('chart');

function onOutside(event: PointerEvent): void {
    if (!chart.value?.contains(event.target as Node)) {
        active.value = null;
    }
}

onMounted(() => document.addEventListener('pointerdown', onOutside));
onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside));

// Последние ответы текстом под графиком у пациента: не всякий догадается коснуться точки.
const recent = computed(() => [...props.points].slice(-3).reverse());

// «21 октября — 4» без переносов внутри: число не отрывается от даты.
function recentLine(point: ScorePoint): string {
    return `${point.date} — ${point.total}`.replaceAll(' ', '\u00a0');
}

function onKeydown(event: KeyboardEvent): void {
    const count = positioned.value.length;

    if (count === 0) {
        return;
    }

    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
        event.preventDefault();
        const step = event.key === 'ArrowRight' ? 1 : -1;
        const current = active.value ?? (step === 1 ? -1 : count);

        active.value = Math.min(count - 1, Math.max(0, current + step));
    } else if (event.key === 'Escape') {
        active.value = null;
    }
}

const summary = computed(() => {
    const point = last.value;

    return point === undefined
        ? `${props.label}: заполнений нет`
        : `${props.label}: последнее значение ${point.total} из ${props.max}, ${point.date}. Все значения — в таблице ниже.`;
});

// Значение выбранной стрелками точки для скринридера: подсказка на графике скрыта от него.
const announcement = computed(() =>
    activePoint.value === null
        ? ''
        : `${activePoint.value.date}: ${activePoint.value.total} из ${props.max}`,
);

// Отмеченные линии сетки (у врача — границы степеней) заметнее линий 0 и наибольшей суммы.
function gridClass(value: number): string {
    return props.ticks.includes(value) ? 'stroke-input' : 'stroke-border';
}

const textSize = computed(() => (props.large ? 'text-base' : 'text-sm'));
const gutter = computed(() =>
    props.large
        ? {
              box: 'pr-8 pl-9',
              plot: 'right-8 left-9',
              tick: '-left-9 w-9',
              axis: 'mr-8 ml-9',
          }
        : {
              box: 'pr-6 pl-8',
              plot: 'right-6 left-8',
              tick: '-left-8 w-8',
              axis: 'mr-6 ml-8',
          },
);
</script>

<template>
    <div class="flex flex-col gap-1">
        <!--
            Слева — подписи сетки, справа место под подпись последней точки. Высота области
            задана вместе с подписями дат, чтобы они не уходили за край.
        -->
        <div
            ref="chart"
            class="relative rounded-md outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
            :class="[large ? 'h-48' : 'h-44', gutter.box]"
            role="img"
            :aria-label="summary"
            tabindex="0"
            data-test="score-chart"
            @keydown="onKeydown"
            @blur="active = null"
        >
            <div ref="plot" class="absolute inset-y-3" :class="gutter.plot">
                <span
                    v-for="value in gridLines"
                    :key="`tick-${value}`"
                    :class="
                        cn(
                            'absolute -translate-y-1/2 pr-2 text-right leading-none text-muted-foreground tabular-nums',
                            gutter.tick,
                            textSize,
                        )
                    "
                    :style="{ top: `${100 - (value / max) * 100}%` }"
                    aria-hidden="true"
                    >{{ value }}</span
                >

                <svg
                    class="absolute inset-0 size-full overflow-visible"
                    viewBox="0 0 100 100"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                >
                    <line
                        v-for="value in gridLines"
                        :key="`grid-${value}`"
                        x1="0"
                        x2="100"
                        :y1="100 - (value / max) * 100"
                        :y2="100 - (value / max) * 100"
                        :class="gridClass(value)"
                        stroke-width="1"
                        vector-effect="non-scaling-stroke"
                    />
                    <line
                        v-if="activePoint"
                        :x1="activePoint.x"
                        :x2="activePoint.x"
                        y1="0"
                        y2="100"
                        class="stroke-muted-foreground/40"
                        stroke-width="1"
                        vector-effect="non-scaling-stroke"
                    />
                    <path
                        v-if="positioned.length > 1"
                        :d="path"
                        fill="none"
                        class="stroke-primary"
                        stroke-width="2"
                        stroke-linejoin="round"
                        stroke-linecap="round"
                        vector-effect="non-scaling-stroke"
                    />
                </svg>

                <span
                    v-for="(point, index) in positioned"
                    :key="point.id"
                    class="absolute size-3 -translate-1/2 rounded-full bg-primary ring-2 ring-card"
                    :class="active === index ? 'scale-125' : ''"
                    :style="{ left: `${point.x}%`, top: `${point.y}%` }"
                    aria-hidden="true"
                />

                <!-- Подпись последней точки: на неё смотрят первой. -->
                <span
                    v-if="last && active === null"
                    :class="
                        cn(
                            'absolute pb-2 leading-none font-semibold text-foreground',
                            large ? 'text-lg' : 'text-sm',
                        )
                    "
                    :style="{
                        left: `${last.x}%`,
                        top: `${last.y}%`,
                        transform: `translate(${last.x > 85 ? '-60%' : '-50%'}, -100%)`,
                    }"
                    aria-hidden="true"
                    data-test="score-chart-last"
                    >{{ last.total }}</span
                >

                <!-- Область касания шире графика на поля: крайние точки легко задеть. -->
                <div
                    class="absolute -inset-x-3 inset-y-0 touch-pan-y"
                    aria-hidden="true"
                    data-test="score-chart-hit"
                    @pointerdown="onPointer"
                    @pointermove="onPointer"
                    @pointerleave="onPointerLeave"
                />

                <div
                    v-if="activePoint"
                    :class="
                        cn(
                            'pointer-events-none absolute z-10 flex flex-col rounded-md border bg-popover px-2 py-1 whitespace-nowrap text-popover-foreground shadow-md',
                            textSize,
                        )
                    "
                    :style="{
                        left: `${activePoint.x}%`,
                        top: `${activePoint.y}%`,
                        transform: `translate(${activePoint.x < 20 ? '0' : activePoint.x > 80 ? '-100%' : '-50%'}, calc(-100% - 0.75rem))`,
                    }"
                    aria-hidden="true"
                    data-test="score-chart-tooltip"
                >
                    <span class="font-semibold tabular-nums"
                        >{{ activePoint.total }} из {{ max }}</span
                    >
                    <span class="text-muted-foreground">{{
                        activePoint.date
                    }}</span>
                </div>
            </div>
        </div>

        <div class="relative h-6" :class="gutter.axis" aria-hidden="true">
            <span
                v-for="point in axisLabels"
                :key="`axis-${point.id}`"
                :class="
                    cn(
                        'absolute top-0 whitespace-nowrap text-muted-foreground tabular-nums',
                        textSize,
                        point.align === 'start'
                            ? ''
                            : point.align === 'end'
                              ? '-translate-x-full'
                              : '-translate-x-1/2',
                    )
                "
                :style="{ left: `${point.x}%` }"
                >{{ point.short }}</span
            >
        </div>

        <!-- Каждый ответ своей строкой: дата не разрывается переносом. -->
        <div
            v-if="large && points.length > 1"
            class="text-muted-foreground"
            aria-hidden="true"
            data-test="score-chart-recent"
        >
            <p>Последние ответы:</p>
            <ul>
                <li v-for="point in recent" :key="`recent-${point.id}`">
                    {{ recentLine(point) }}
                </li>
            </ul>
        </div>

        <p class="sr-only" aria-live="polite">{{ announcement }}</p>

        <table class="sr-only">
            <caption>
                {{
                    label
                }}
            </caption>
            <thead>
                <tr>
                    <th scope="col">Дата</th>
                    <th scope="col">Сумма баллов из {{ max }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="point in points" :key="`row-${point.id}`">
                    <td>{{ point.date }}</td>
                    <td>{{ point.total }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
