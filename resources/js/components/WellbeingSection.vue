<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowUpRight,
    CalendarClock,
    ClipboardPen,
    ExternalLink,
    Minus,
    Siren,
} from '@lucide/vue';
import DoctorScaleController from '@/actions/App/Http/Controllers/Doctor/DoctorScaleController';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import ScoreChart from '@/components/ScoreChart.vue';
import { Button } from '@/components/ui/button';
import { CHIP_ICON, DANGER_CHIP } from '@/lib/chips';
import type {
    AssessmentFilling,
    DoctorScale,
    RatableScale,
    Wellbeing,
} from '@/types';

/**
 * Блок «Самочувствие» в карточке пациента: последние суммы опросников со степенью и изменением,
 * график по датам и заполнения с ответами на каждый вопрос. Ответ на отмеченный вопрос
 * (9-й PHQ-9, мысли о смерти) виден в строке каждого заполнения. Шкалу врача (HARS) врач
 * заполняет на приёме кнопкой в шапке блока.
 */
const props = withDefaults(
    defineProps<{
        wellbeing: Wellbeing;
        patientId?: number | null;
        // Шкалы, которые врач может заполнить; пусто — только просмотр.
        rateScales?: RatableScale[];
    }>(),
    { patientId: null, rateScales: () => [] },
);

// Сначала видны последние заполнения, остальные — по кнопке.
const VISIBLE_FILLINGS = 5;
const showAll = ref(false);
const fillingList = useTemplateRef<HTMLElement>('fillingList');

// Кнопка исчезает: фокус переходит на первое из показанных заполнений.
async function showAllFillings(): Promise<void> {
    showAll.value = true;
    await nextTick();
    fillingList.value
        ?.querySelectorAll<HTMLElement>('summary')
        [VISIBLE_FILLINGS]?.focus();
}

const fillings = computed(() =>
    showAll.value
        ? props.wellbeing.fillings
        : props.wellbeing.fillings.slice(0, VISIBLE_FILLINGS),
);

function change(scale: DoctorScale): { delta: number; text: string } | null {
    if (scale.latest === null || scale.previous === null) {
        return null;
    }

    const delta = scale.latest.total - scale.previous.total;
    const sign = delta > 0 ? '+' : delta < 0 ? '−' : '';

    return {
        delta,
        text:
            delta === 0
                ? `без изменений с ${scale.previous.date}`
                : `${sign}${Math.abs(delta)} с ${scale.previous.date}`,
    };
}

// Источник одной строкой: текст и <template v-if> подряд на сервере сливаются в один узел.
function sourceText(scale: DoctorScale): string {
    const { original, validation, license, note } = scale.source;

    return [
        `${scale.name}: ${original}`,
        `Русская версия: ${validation}`,
        license,
        note,
    ]
        .filter(Boolean)
        .join(' ');
}

// Ответы на отмеченные вопросы заполнения (9-й PHQ-9): видны в строке без раскрытия.
function highlighted(filling: AssessmentFilling) {
    return filling.assessments.flatMap((assessment) =>
        assessment.answers
            .filter((answer) => answer.highlight)
            .map((answer) => ({ ...answer, scale: assessment.name })),
    );
}
</script>

<template>
    <section
        id="wellbeing"
        class="flex scroll-mt-20 flex-col gap-3"
        aria-labelledby="wellbeing-title"
        data-test="wellbeing"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <h2 id="wellbeing-title" class="text-lg font-semibold">
                    Самочувствие
                </h2>
                <p class="text-sm text-muted-foreground">
                    Пациент заполняет GAD-7 (тревога) и PHQ-9 (депрессия) за
                    последние 2 недели. Сроки от начала наблюдения: день 0, 7,
                    14, 28, дальше каждые 4 недели. HARS заполняет врач на
                    приёме.
                </p>
            </div>
            <Button
                v-for="scale in patientId === null ? [] : rateScales"
                :key="scale.code"
                variant="outline"
                class="max-sm:w-full"
                as-child
            >
                <Link
                    :href="
                        DoctorScaleController.create({
                            patient: patientId!,
                            scale: scale.code,
                        })
                    "
                    :data-test="`rate-${scale.code}`"
                >
                    <ClipboardPen />
                    {{ `Заполнить ${scale.name}` }}
                </Link>
            </Button>
        </div>

        <p
            v-if="wellbeing.status"
            class="flex items-center gap-2 text-sm"
            data-test="wellbeing-status"
        >
            <CalendarClock class="size-4 shrink-0 text-muted-foreground" />
            <template v-if="wellbeing.status.due">
                Срок {{ wellbeing.status.due_since }}: ответов пока нет.
            </template>
            <template v-else>
                Следующие опросники пациент получит {{ wellbeing.status.next }}.
            </template>
        </p>

        <p
            v-if="wellbeing.fillings.length === 0"
            class="rounded-xl border border-dashed bg-card p-4 text-sm text-muted-foreground"
        >
            Когда наступает срок, опросники появляются у пациента на экране
            «Сегодня».
        </p>

        <template v-else>
            <!--
                Опросники друг под другом: сводка слева, график справа во всю оставшуюся
                ширину. На узком экране — сводка над графиком.
            -->
            <article
                v-for="scale in wellbeing.scales"
                :key="scale.code"
                class="grid grid-cols-[minmax(0,1fr)] gap-4 rounded-xl border bg-card p-4 shadow-xs @3xl:grid-cols-[13rem_minmax(0,1fr)]"
                :aria-labelledby="`wellbeing-${scale.code}-title`"
                :data-test="`wellbeing-${scale.code}`"
            >
                <div class="flex flex-col gap-2">
                    <div class="flex flex-col">
                        <h3
                            :id="`wellbeing-${scale.code}-title`"
                            class="font-semibold"
                        >
                            {{ scale.name }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            {{ scale.title }}
                        </p>
                    </div>

                    <template v-if="scale.latest">
                        <p class="text-3xl font-semibold">
                            {{ scale.latest.total
                            }}<span
                                class="text-base font-normal text-muted-foreground"
                            >
                                из {{ scale.max }}</span
                            >
                        </p>
                        <p class="font-medium" data-test="wellbeing-band">
                            {{ scale.latest.band }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ scale.latest.date }}
                        </p>
                        <p
                            v-if="change(scale)"
                            class="inline-flex items-center gap-1 text-sm"
                            data-test="wellbeing-change"
                        >
                            <ArrowDownRight
                                v-if="change(scale)!.delta < 0"
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <ArrowUpRight
                                v-else-if="change(scale)!.delta > 0"
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <Minus
                                v-else
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            {{ change(scale)!.text }}
                        </p>
                        <p
                            v-if="
                                scale.first &&
                                scale.first.total !== scale.latest.total
                            "
                            class="text-sm text-muted-foreground"
                            data-test="wellbeing-since-first"
                        >
                            {{
                                `с первого заполнения ${scale.first.date}: было ${scale.first.total}, стало ${scale.latest.total}`
                            }}
                        </p>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            scale.rater === 'doctor'
                                ? 'Не заполнялась.'
                                : 'Не заполнялся.'
                        }}
                    </p>
                </div>

                <ScoreChart
                    v-if="scale.points.length"
                    :points="scale.points"
                    :max="scale.max"
                    :ticks="scale.thresholds"
                    :label="`${scale.name}, баллы по датам`"
                />
            </article>

            <h3 class="mt-2 font-semibold">Заполнения</h3>
            <ul
                ref="fillingList"
                class="flex flex-col gap-2"
                data-test="wellbeing-fillings"
            >
                <li
                    v-for="filling in fillings"
                    :key="filling.submitted_at + filling.assessments[0]?.id"
                >
                    <details
                        class="group rounded-xl border bg-card shadow-xs"
                        data-test="wellbeing-filling"
                    >
                        <summary
                            class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-1 rounded-xl px-4 py-3 text-sm hover:bg-muted pointer-coarse:min-h-11 [&::-webkit-details-marker]:hidden"
                        >
                            <span class="font-medium tabular-nums">{{
                                filling.submitted_at
                            }}</span>
                            <span
                                v-for="assessment in filling.assessments"
                                :key="assessment.id"
                            >
                                {{ assessment.name }}:
                                <span class="font-semibold tabular-nums">{{
                                    assessment.total
                                }}</span>
                                <span class="text-muted-foreground">
                                    · {{ assessment.band }}</span
                                >
                                <span
                                    v-if="assessment.rated_by"
                                    class="text-muted-foreground"
                                    data-test="wellbeing-rated-by"
                                >
                                    · {{ `врач ${assessment.rated_by}` }}</span
                                >
                            </span>
                            <span
                                v-for="answer in highlighted(filling)"
                                :key="`${answer.scale}-${answer.item}`"
                                :class="
                                    answer.score > 0
                                        ? DANGER_CHIP
                                        : 'text-muted-foreground'
                                "
                                data-test="wellbeing-highlight"
                            >
                                <!-- Иконка алерта: такой ответ на 9-й вопрос PHQ-9 его и вызывает. -->
                                <Siren
                                    v-if="answer.score > 0"
                                    :class="CHIP_ICON"
                                />
                                {{ answer.scale }}, вопрос {{ answer.item }}:
                                {{ answer.label.toLowerCase() }} ({{
                                    answer.score
                                }})
                            </span>
                            <span class="ml-auto text-primary group-open:hidden"
                                >Ответы</span
                            >
                            <span
                                class="ml-auto hidden text-primary group-open:inline"
                                >Скрыть</span
                            >
                        </summary>

                        <div class="flex flex-col gap-4 border-t px-4 py-3">
                            <table
                                v-for="assessment in filling.assessments"
                                :key="`answers-${assessment.id}`"
                                class="w-full text-left text-sm"
                            >
                                <caption class="pb-1 text-left font-semibold">
                                    {{
                                        `${assessment.name}: ${assessment.total} из ${assessment.max}, ${assessment.band}`
                                    }}
                                </caption>
                                <thead class="sr-only">
                                    <tr>
                                        <th scope="col">Вопрос</th>
                                        <th scope="col">Ответ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="answer in assessment.answers"
                                        :key="answer.item"
                                        class="border-b last:border-b-0"
                                        :class="
                                            answer.highlight && answer.score > 0
                                                ? 'bg-danger-subtle'
                                                : ''
                                        "
                                    >
                                        <td class="py-1.5 pr-3 align-top">
                                            <span
                                                class="text-muted-foreground tabular-nums"
                                                >{{ answer.item }}.</span
                                            >
                                            {{ answer.text }}
                                            <span
                                                v-if="answer.hint"
                                                class="block text-muted-foreground"
                                                >{{ answer.hint }}</span
                                            >
                                        </td>
                                        <td
                                            class="w-2/5 py-1.5 align-top"
                                            :class="
                                                answer.score > 0
                                                    ? 'font-semibold'
                                                    : ''
                                            "
                                        >
                                            {{ answer.label }}
                                            <span
                                                class="text-muted-foreground tabular-nums"
                                                >({{ answer.score }})</span
                                            >
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </li>
            </ul>
            <Button
                v-if="!showAll && wellbeing.fillings.length > VISIBLE_FILLINGS"
                variant="outline"
                size="sm"
                class="self-start"
                @click="showAllFillings"
            >
                Показать все заполнения ({{ wellbeing.fillings.length }})
            </Button>
        </template>

        <!-- Полные ссылки на публикации длинные: свёрнуты, чтобы не заслонять ответы. -->
        <details
            v-if="wellbeing.scales.length"
            class="text-sm text-muted-foreground"
            data-test="wellbeing-sources"
        >
            <summary
                class="cursor-pointer rounded-md text-primary pointer-coarse:flex pointer-coarse:min-h-11 pointer-coarse:items-center"
            >
                Источники и условия использования
            </summary>
            <p
                v-for="scale in wellbeing.scales"
                :key="`source-${scale.code}`"
                class="mt-1"
            >
                {{ sourceText(scale) }}
                <a
                    :href="scale.source.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-primary underline underline-offset-4"
                >
                    Открыть документ<span class="sr-only">{{
                        ` ${scale.name}`
                    }}</span>
                    <ExternalLink class="size-4" />
                </a>
            </p>
        </details>
    </section>
</template>
