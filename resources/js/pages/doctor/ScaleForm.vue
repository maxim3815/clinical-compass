<script lang="ts">
import DoctorScaleController from '@/actions/App/Http/Controllers/Doctor/DoctorScaleController';
import QueueAnswerController from '@/actions/App/Http/Controllers/Doctor/QueueAnswerController';
import QueueAnswerScaleController from '@/actions/App/Http/Controllers/Doctor/QueueAnswerScaleController';
import { show } from '@/routes/doctor/patients';
import type { DoctorScaleForm, ScreeningFact } from '@/types';

/**
 * Шкала врача на приёме. HARS у психиатра: пункт — одна строка с оценками 0–4, подписи оценок
 * один раз сверху. HADS у терапевта: у каждого утверждения свои варианты ответа словами, без
 * баллов — терапевт зачитывает их или даёт пациенту ответить на своём экране. Сумма и степень
 * (у HADS — по тревоге и депрессии) закреплены внизу. Терапевт ниже отмечает признаки, которых
 * нет в шкалах. Заполняют для пациента или по ответам из очереди до регистрации (по коду).
 */
type Props = {
    patient: { id: number; name: string } | null;
    queueAnswer: { id: number; code: string } | null;
    scale: DoctorScaleForm;
    facts: ScreeningFact[];
};

// Для кого шкала: пациент или ответы из очереди. Ровно одно из двух.
function subject(props: Props) {
    if (props.patient !== null) {
        return {
            name: props.patient.name,
            back: show(props.patient.id),
            create: DoctorScaleController.create({
                patient: props.patient.id,
                scale: props.scale.code,
            }),
            store: DoctorScaleController.store({
                patient: props.patient.id,
                scale: props.scale.code,
            }),
        };
    }

    const answer = props.queueAnswer as { id: number; code: string };

    return {
        name: `Код ${answer.code}`,
        back: QueueAnswerController.show(answer.id),
        create: QueueAnswerScaleController.create({
            queueAnswer: answer.id,
            scale: props.scale.code,
        }),
        store: QueueAnswerScaleController.store({
            queueAnswer: answer.id,
            scale: props.scale.code,
        }),
    };
}
</script>

<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CircleAlert, ExternalLink } from '@lucide/vue';
import { useResizeObserver } from '@vueuse/core';
import { computed, nextTick, onBeforeUnmount, ref, useTemplateRef } from 'vue';
import LeaveConfirmDialog from '@/components/LeaveConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import { plural } from '@/lib/plural';
import { index } from '@/routes/doctor/patients';

const props = defineProps<Props>();

defineOptions({
    layout: (props: Props) => ({
        breadcrumbs: [
            { title: 'Пациенты', href: index() },
            { title: subject(props).name, href: subject(props).back },
            { title: props.scale.name, href: subject(props).create },
        ],
    }),
});

const target = computed(() => subject(props));

const form = useForm<{
    scores: (number | null)[];
    facts: Record<string, boolean | null>;
}>({
    scores: props.scale.items.map(() => null),
    facts: Object.fromEntries(
        props.facts.map((fact) => [fact.code, fact.value]),
    ),
});

// Пропуски подсвечиваются после первой попытки сохранить и гаснут сами, когда пункт оценён.
const attempted = ref(false);
// Видимая ошибка считается на лету, а скринридер слышит её один раз — в момент нажатия
// «Сохранить»: живой счётчик перебивал бы его на каждом выборе.
const announced = ref('');

const unanswered = computed(() =>
    form.scores.flatMap((score, item) => (score === null ? [item] : [])),
);

// Варианты свои у каждого утверждения (HADS): подписи словами в строке пункта, баллы скрыты.
const ownOptions = computed(() =>
    props.scale.items.some((item) => item.options !== null),
);

function optionsFor(position: number) {
    return props.scale.items[position]?.options ?? props.scale.options;
}

const itemWords = computed<[string, string, string]>(() =>
    ownOptions.value
        ? ['утверждение', 'утверждения', 'утверждений']
        : ['пункт', 'пункта', 'пунктов'],
);

const missingFacts = computed(() =>
    props.facts.filter((fact) => form.facts[fact.code] === null),
);

const total = computed(() =>
    form.scores.reduce<number>((sum, score) => sum + (score ?? 0), 0),
);

function bandOf(
    bands: DoctorScaleForm['bands'],
    sum: number,
): string | undefined {
    return bands.find((band) => sum >= band.min && sum <= band.max)?.label;
}

const band = computed(() => bandOf(props.scale.bands, total.value) ?? null);

// У HADS экран может держать пациент: сумм и степеней до сохранения нет, итог — после
// сохранения в уведомлении и карточке. У HARS врач видит сумму сразу.
const summary = computed(() => {
    const answered = props.scale.items.length - unanswered.value.length;

    if (ownOptions.value) {
        return unanswered.value.length > 0
            ? `Отмечено ${answered} из ${props.scale.items.length}.`
            : `Отмечено ${answered} из ${props.scale.items.length}. Суммы — после сохранения.`;
    }

    return unanswered.value.length > 0
        ? `Оценено ${answered} из ${props.scale.items.length}.`
        : `Сумма ${total.value} из ${props.scale.max}: ${band.value}.`;
});

// Признаки отмечает врач: у HADS блок свёрнут, чтобы пациент с экраном в руках их не читал.
const factsOpen = ref(!ownOptions.value);

// Признаки не отмечены: блок не раскрывали (значения из профиля врач не проверил — сохранить
// мог и пациент) или в нём есть пустые.
const factsPending = computed(
    () =>
        props.facts.length > 0 &&
        (!factsOpen.value || missingFacts.value.length > 0),
);

async function openFacts(): Promise<void> {
    factsOpen.value = true;
    await nextTick();
    document
        .querySelector<HTMLElement>('[data-test="scale-facts"] input')
        ?.focus();
}

function missingText(): string {
    if (unanswered.value.length === 0) {
        return factsOpen.value
            ? 'Отметьте, есть ли каждый признак.'
            : 'Отметьте признаки: их заполняет врач.';
    }

    const count = `${unanswered.value.length} ${plural(unanswered.value.length, itemWords.value)}`;

    return ownOptions.value
        ? `Нет ответа ещё на ${count}.`
        : `Оцените ещё ${count}.`;
}

// Одна короткая строка: чего не хватает после попытки сохранить, пока пропуски остаются. Ошибки сервера — следом.
const error = computed(() => {
    if (
        attempted.value &&
        (unanswered.value.length > 0 || factsPending.value)
    ) {
        return missingText();
    }

    const server = Object.entries(form.errors).find(
        ([key]) =>
            key === 'scores' ||
            key.startsWith('scores.') ||
            key.startsWith('facts'),
    );

    return server?.[1];
});

const leaveGuard = useLeaveGuard(
    () => form.isDirty && !form.processing && !form.wasSuccessful,
);

function missing(position: number): boolean {
    return attempted.value && form.scores[position] === null;
}

// Первый пропуск — по центру экрана целиком, с текстом утверждения: фокус на радиокнопке
// прокрутил бы только к ней, к краю под шапку или панель.
// Свёрнутые признаки сами не раскрываются: «Сохранить» мог нажать пациент. Фокус — на кнопку.
async function focusFirstMissing(): Promise<void> {
    await nextTick();

    let selector = '[data-test="scale-error"]';

    if (unanswered.value.length > 0) {
        selector = `input[name="score-${unanswered.value[0]}"]`;
    } else if (props.facts.length > 0 && !factsOpen.value) {
        selector = '[data-test="open-scale-facts"]';
    } else if (missingFacts.value.length > 0) {
        selector = `input[name="fact-${missingFacts.value[0].code}"]`;
    }

    const target = document.querySelector<HTMLElement>(selector);

    target
        ?.closest('fieldset, [role="radiogroup"]')
        ?.scrollIntoView({ block: 'center' });
    target?.focus({ preventScroll: true });
}

// Высота закреплённой панели: на неё браузер отступает, прокручивая к полю в фокусе (app.css).
const saveBar = useTemplateRef<HTMLElement>('saveBar');

useResizeObserver(saveBar, () =>
    document.documentElement.style.setProperty(
        '--scale-save-bar-height',
        `${saveBar.value?.offsetHeight ?? 0}px`,
    ),
);

onBeforeUnmount(() =>
    document.documentElement.style.removeProperty('--scale-save-bar-height'),
);

function optionLabel(position: number, score: number | null): string {
    return (
        optionsFor(position).find((option) => option.score === score)?.label ??
        ''
    );
}

function preventRadioEnter(event: KeyboardEvent): void {
    if (
        event.target instanceof HTMLInputElement &&
        event.target.type === 'radio'
    ) {
        event.preventDefault();
    }
}

async function submit(): Promise<void> {
    attempted.value = true;
    // Пустая строка и новый текст в разных тиках: тот же текст при повторном нажатии объявляется снова.
    announced.value = '';

    if (unanswered.value.length > 0 || factsPending.value) {
        await nextTick();
        announced.value = missingText();
        void focusFirstMissing();

        return;
    }

    form.transform((data) =>
        props.facts.length > 0 ? data : { scores: data.scores },
    ).submit(target.value.store, {
        onError: () => void focusFirstMissing(),
    });
}
</script>

<template>
    <Head :title="`${scale.name}: ${target.name}`" />

    <div class="@container flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ scale.title }}
            </h1>
            <p class="text-muted-foreground">
                {{
                    `${target.name} · ${scale.name}, ${scale.items.length} ${plural(scale.items.length, itemWords)}`
                }}
            </p>
        </header>

        <div class="flex flex-col gap-2 text-sm">
            <details class="rounded-xl border bg-card px-4 py-3 shadow-xs">
                <summary
                    class="w-fit cursor-pointer rounded-sm font-medium text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid pointer-coarse:flex pointer-coarse:min-h-11 pointer-coarse:items-center"
                >
                    Как заполнять
                </summary>
                <p class="mt-2">{{ scale.instruction }}</p>
                <p v-if="scale.source.note" class="mt-2 text-muted-foreground">
                    {{ scale.source.note }}
                </p>
                <!-- Источник свёрнут: пациенту с экраном в руках название чужой рекомендации ни к чему. -->
                <p class="mt-2 text-muted-foreground">
                    {{ `Текст шкалы: ${scale.source.validation}` }}
                    <a
                        :href="scale.source.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 text-primary underline underline-offset-4"
                    >
                        Открыть документ
                        <ExternalLink class="size-4" />
                    </a>
                </p>
            </details>
        </div>

        <!-- Enter на радиокнопке (утверждения и признаки) не отправляет форму: фокус прыгал бы к пропуску. -->
        <form
            class="flex flex-col gap-4"
            novalidate
            @submit.prevent="submit"
            @keydown.enter="preventRadioEnter"
        >
            <!-- Для того, кто отвечает: у HADS — о каком времени речь. -->
            <p
                v-if="scale.prompt"
                class="text-base font-medium"
                data-test="scale-prompt"
            >
                {{ scale.prompt }}
            </p>

            <!-- Подписи оценок один раз: в строках пунктов только цифры. -->
            <p
                v-if="!ownOptions"
                id="scale-options"
                class="text-sm"
                data-test="scale-options"
            >
                <template
                    v-for="(option, position) in scale.options"
                    :key="option.score"
                >
                    <span class="font-semibold tabular-nums">{{
                        option.score
                    }}</span
                    ><span>{{
                        ` — ${option.label.toLowerCase()}${position < scale.options.length - 1 ? ' · ' : ''}`
                    }}</span>
                </template>
            </p>

            <ol
                class="divide-y overflow-hidden rounded-xl border bg-card shadow-xs"
            >
                <li v-for="(item, position) in scale.items" :key="position">
                    <!-- Пропуск — полосой слева и словами, без заливки: красного не больше, чем нужно. -->
                    <fieldset
                        class="grid grid-cols-[minmax(0,1fr)] items-center gap-x-6 gap-y-2 border-l-4 py-3 pr-4 pl-3"
                        :class="[
                            missing(position)
                                ? 'border-l-danger'
                                : 'border-l-transparent',
                            ownOptions
                                ? ''
                                : '@2xl:grid-cols-[minmax(0,1fr)_auto]',
                        ]"
                        role="radiogroup"
                        aria-required="true"
                        :aria-invalid="missing(position) ? true : undefined"
                        :aria-labelledby="`scale-item-${position}-title`"
                        :aria-describedby="
                            [
                                item.hint ? `scale-item-${position}-hint` : '',
                                missing(position)
                                    ? `scale-item-${position}-missing`
                                    : '',
                            ]
                                .filter(Boolean)
                                .join(' ') || undefined
                        "
                        :data-test="`scale-item-${position}`"
                    >
                        <div class="min-w-0">
                            <!-- Имя группы — из заголовка пункта: legend внутри сетки браузер не берёт. -->
                            <p
                                :id="`scale-item-${position}-title`"
                                class="font-semibold"
                            >
                                {{ `${position + 1}. ${item.text}` }}
                            </p>
                            <p
                                v-if="item.hint"
                                :id="`scale-item-${position}-hint`"
                                class="text-sm text-muted-foreground"
                            >
                                {{ item.hint }}
                            </p>
                            <p
                                v-if="missing(position)"
                                :id="`scale-item-${position}-missing`"
                                class="mt-1 flex items-center gap-1 text-sm font-medium text-danger"
                            >
                                <CircleAlert
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ ownOptions ? 'Нет ответа' : 'Не оценено' }}
                            </p>
                        </div>
                        <!--
                            HADS: варианты словами, баллы не видны — отвечать может сам пациент. Порядок
                            вариантов строго по строке: столбик или четыре в ряд, без сетки 2×2.
                        -->
                        <div
                            v-if="ownOptions"
                            class="grid grid-cols-1 gap-2 @3xl:grid-cols-4"
                        >
                            <label
                                v-for="option in optionsFor(position)"
                                :key="option.score"
                                class="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-input bg-card px-3 py-2 text-base transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid"
                                :data-test="`score-${position}-${option.score}`"
                            >
                                <input
                                    v-model="form.scores[position]"
                                    type="radio"
                                    :name="`score-${position}`"
                                    :value="option.score"
                                    class="size-4 shrink-0 outline-none"
                                />
                                <span class="block first-letter:uppercase">{{
                                    option.label
                                }}</span>
                            </label>
                        </div>
                        <div v-else class="flex flex-col gap-1">
                            <div class="flex gap-1.5">
                                <label
                                    v-for="option in scale.options"
                                    :key="option.score"
                                    class="flex size-11 cursor-pointer items-center justify-center rounded-lg border border-input bg-card font-semibold tabular-nums transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid"
                                    :title="option.label"
                                    :data-test="`score-${position}-${option.score}`"
                                >
                                    <input
                                        v-model="form.scores[position]"
                                        type="radio"
                                        :name="`score-${position}`"
                                        :value="option.score"
                                        class="sr-only"
                                        :aria-label="`${option.score} — ${option.label}`"
                                    />
                                    <span aria-hidden="true">{{
                                        option.score
                                    }}</span>
                                </label>
                            </div>
                            <!-- Подпись выбранной оценки: расшифровка сверху к середине формы уходит из виду. -->
                            <p
                                class="min-h-5 text-sm text-muted-foreground"
                                aria-hidden="true"
                            >
                                {{
                                    optionLabel(position, form.scores[position])
                                }}
                            </p>
                        </div>
                    </fieldset>
                </li>
            </ol>

            <!-- Признаки — часть врача, не шкалы: у HADS свёрнуты до нажатия или пропуска при сохранении. -->
            <Button
                v-if="facts.length > 0 && !factsOpen"
                type="button"
                variant="outline"
                class="self-start"
                aria-controls="scale-facts"
                :aria-expanded="false"
                data-test="open-scale-facts"
                @click="openFacts"
            >
                Отметить признаки (заполняет врач)
            </Button>
            <fieldset
                v-if="facts.length > 0"
                v-show="factsOpen"
                id="scale-facts"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
                aria-describedby="scale-facts-hint"
                data-test="scale-facts"
            >
                <legend class="float-left w-full font-semibold">
                    Признаки, которых нет в шкалах · заполняет врач
                </legend>
                <p
                    id="scale-facts-hint"
                    class="clear-left text-sm text-muted-foreground"
                >
                    Любой из них — основание для направления к психиатру
                    «обязательно». Отметка сохранится в клиническом профиле
                    пациента.
                </p>
                <!-- Пропуск — как у утверждений: полоса слева, без заливки. -->
                <div
                    v-for="fact in facts"
                    :key="fact.code"
                    class="clear-left flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-l-4 pl-2"
                    :class="
                        attempted && form.facts[fact.code] === null
                            ? 'border-l-danger'
                            : 'border-l-transparent'
                    "
                    role="radiogroup"
                    aria-required="true"
                    :aria-invalid="
                        attempted && form.facts[fact.code] === null
                            ? true
                            : undefined
                    "
                    :aria-labelledby="`fact-${fact.code}-label`"
                >
                    <p :id="`fact-${fact.code}-label`">{{ fact.name }}</p>
                    <div class="flex gap-1.5">
                        <label
                            v-for="choice in [
                                { value: false, label: 'Нет' },
                                { value: true, label: 'Есть' },
                            ]"
                            :key="choice.label"
                            class="flex min-h-11 min-w-20 cursor-pointer items-center gap-2 rounded-lg border border-input bg-card px-3 py-2 text-sm transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-checked:font-semibold has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid"
                            :data-test="`fact-${fact.code}-${choice.value ? 'yes' : 'no'}`"
                        >
                            <input
                                v-model="form.facts[fact.code]"
                                type="radio"
                                :name="`fact-${fact.code}`"
                                :value="choice.value"
                                class="size-4 shrink-0 outline-none"
                            />
                            {{ choice.label }}
                        </label>
                    </div>
                </div>
            </fieldset>

            <!-- Сумма и сохранение закреплены внизу: пунктов много, а итог нужен всё время. -->
            <div
                ref="saveBar"
                class="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t bg-background/95 px-4 py-3 backdrop-blur-sm md:-mx-6 md:px-6"
                data-test="scale-save-bar"
            >
                <div class="min-w-0 flex-1 max-sm:basis-full">
                    <p class="text-sm font-medium" data-test="scale-summary">
                        {{ summary }}
                    </p>
                    <p
                        v-if="error"
                        id="scale-error"
                        tabindex="-1"
                        class="text-sm text-danger outline-none"
                        data-test="scale-error"
                    >
                        {{ error }}
                    </p>
                    <p class="sr-only" role="alert">{{ announced }}</p>
                </div>
                <div class="flex gap-2 max-sm:w-full sm:ml-auto">
                    <Button variant="outline" class="max-sm:flex-1" as-child>
                        <Link :href="target.back">Отмена</Link>
                    </Button>
                    <Button
                        type="submit"
                        class="max-sm:flex-1"
                        :disabled="form.processing"
                        :aria-busy="form.processing"
                        data-test="save-scale"
                    >
                        <Spinner v-if="form.processing" />
                        Сохранить
                    </Button>
                </div>
            </div>
        </form>

        <LeaveConfirmDialog
            :pending="leaveGuard.pending.value"
            :lost="`${ownOptions ? 'Ответы' : 'Оценки'} ${scale.name} не сохранятся.`"
            @leave="leaveGuard.leave"
            @stay="leaveGuard.stay"
        />
    </div>
</template>
