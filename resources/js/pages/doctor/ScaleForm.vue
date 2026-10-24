<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CircleAlert, ExternalLink } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import DoctorScaleController from '@/actions/App/Http/Controllers/Doctor/DoctorScaleController';
import LeaveConfirmDialog from '@/components/LeaveConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import { plural } from '@/lib/plural';
import { index, show } from '@/routes/doctor/patients';
import type { DoctorScaleForm, ScreeningFact } from '@/types';

/**
 * Шкала врача на приёме (HARS): пункт — одна строка с оценками 0–4, подписи оценок один раз
 * сверху, сумма и степень закреплены внизу. Терапевт ниже отмечает признаки, которых нет в шкалах.
 */
type Props = {
    patient: { id: number; name: string };
    scale: DoctorScaleForm;
    facts: ScreeningFact[];
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: Props) => ({
        breadcrumbs: [
            { title: 'Пациенты', href: index() },
            { title: props.patient.name, href: show(props.patient.id) },
            {
                title: props.scale.name,
                href: DoctorScaleController.create({
                    patient: props.patient.id,
                    scale: props.scale.code,
                }),
            },
        ],
    }),
});

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
// Текст ошибки — на момент нажатия «Сохранить»: живой счётчик перебивал бы скринридер на каждом выборе.
const submitError = ref<string | null>(null);

const unanswered = computed(() =>
    form.scores.flatMap((score, item) => (score === null ? [item] : [])),
);

const missingFacts = computed(() =>
    props.facts.filter((fact) => form.facts[fact.code] === null),
);

const total = computed(() =>
    form.scores.reduce<number>((sum, score) => sum + (score ?? 0), 0),
);

const band = computed(
    () =>
        props.scale.bands.find(
            (band) => total.value >= band.min && total.value <= band.max,
        )?.label ?? null,
);

const summary = computed(() =>
    unanswered.value.length > 0
        ? `Оценено ${props.scale.items.length - unanswered.value.length} из ${props.scale.items.length}.`
        : `Сумма ${total.value} из ${props.scale.max}: ${band.value}.`,
);

function missingText(): string {
    return unanswered.value.length > 0
        ? `Оцените ещё ${unanswered.value.length} ${plural(unanswered.value.length, ['пункт', 'пункта', 'пунктов'])}.`
        : 'Отметьте, есть ли каждый признак.';
}

// Одна короткая строка: чего не хватало при сохранении, пока пропуски остаются. Ошибки сервера — следом.
const error = computed(() => {
    if (
        submitError.value !== null &&
        (unanswered.value.length > 0 || missingFacts.value.length > 0)
    ) {
        return submitError.value;
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

async function focusFirstMissing(): Promise<void> {
    await nextTick();

    const selector =
        unanswered.value.length > 0
            ? `input[name="score-${unanswered.value[0]}"]`
            : missingFacts.value.length > 0
              ? `input[name="fact-${missingFacts.value[0].code}"]`
              : '[data-test="scale-error"]';

    document.querySelector<HTMLElement>(selector)?.focus();
}

function optionLabel(score: number | null): string {
    return (
        props.scale.options.find((option) => option.score === score)?.label ??
        ''
    );
}

function submit(): void {
    attempted.value = true;
    submitError.value = null;

    if (unanswered.value.length > 0 || missingFacts.value.length > 0) {
        submitError.value = missingText();
        void focusFirstMissing();

        return;
    }

    form.transform((data) =>
        props.facts.length > 0 ? data : { scores: data.scores },
    ).submit(
        DoctorScaleController.store({
            patient: props.patient.id,
            scale: props.scale.code,
        }),
        { onError: () => void focusFirstMissing() },
    );
}
</script>

<template>
    <Head :title="`${scale.name}: ${patient.name}`" />

    <div class="@container flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ scale.title }}
            </h1>
            <p class="text-muted-foreground">
                {{
                    `${patient.name} · ${scale.name}, ${scale.items.length} пунктов`
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
            </details>
            <p class="text-muted-foreground">
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
        </div>

        <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <!-- Подписи оценок один раз: в строках пунктов только цифры. -->
            <p id="scale-options" class="text-sm" data-test="scale-options">
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

            <ol class="divide-y rounded-xl border bg-card shadow-xs">
                <li v-for="(item, position) in scale.items" :key="position">
                    <fieldset
                        class="grid grid-cols-[minmax(0,1fr)] items-center gap-x-6 gap-y-2 px-4 py-3 @2xl:grid-cols-[minmax(0,1fr)_auto]"
                        :class="missing(position) ? 'bg-danger-subtle' : ''"
                        :aria-labelledby="`scale-item-${position}-title`"
                        :aria-describedby="
                            [
                                item.hint ? `scale-item-${position}-hint` : '',
                                missing(position) ? 'scale-error' : '',
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
                                class="mt-1 flex items-center gap-1 text-sm font-medium text-danger"
                            >
                                <CircleAlert
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                Не оценено
                            </p>
                        </div>
                        <div class="flex flex-col gap-1">
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
                                        :aria-invalid="
                                            missing(position) ? true : undefined
                                        "
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
                                {{ optionLabel(form.scores[position]) }}
                            </p>
                        </div>
                    </fieldset>
                </li>
            </ol>

            <fieldset
                v-if="facts.length > 0"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
                aria-describedby="scale-facts-hint"
                data-test="scale-facts"
            >
                <legend class="float-left w-full font-semibold">
                    Признаки, которых нет в шкалах
                </legend>
                <p
                    id="scale-facts-hint"
                    class="clear-left text-sm text-muted-foreground"
                >
                    Любой из них — основание для направления к психиатру
                    «обязательно». Отметка сохранится в клиническом профиле
                    пациента.
                </p>
                <div
                    v-for="fact in facts"
                    :key="fact.code"
                    class="clear-left flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-lg"
                    :class="
                        attempted && form.facts[fact.code] === null
                            ? 'bg-danger-subtle p-2'
                            : ''
                    "
                    role="radiogroup"
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
                                :aria-invalid="
                                    attempted && form.facts[fact.code] === null
                                        ? true
                                        : undefined
                                "
                            />
                            {{ choice.label }}
                        </label>
                    </div>
                </div>
            </fieldset>

            <!-- Сумма и сохранение закреплены внизу: пунктов много, а итог нужен всё время. -->
            <div
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
                        role="alert"
                        class="text-sm text-danger outline-none"
                        data-test="scale-error"
                    >
                        {{ error }}
                    </p>
                </div>
                <div class="flex gap-2 max-sm:w-full sm:ml-auto">
                    <Button variant="outline" class="max-sm:flex-1" as-child>
                        <Link :href="show(patient.id)">Отмена</Link>
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
            :lost="`Оценки ${scale.name} не сохранятся.`"
            @leave="leaveGuard.leave"
            @stay="leaveGuard.stay"
        />
    </div>
</template>
