<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { OctagonAlert, TriangleAlert } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import PrescriptionController from '@/actions/App/Http/Controllers/Doctor/PrescriptionController';
import SafetyCheckController from '@/actions/App/Http/Controllers/Doctor/SafetyCheckController';
import InputError from '@/components/InputError.vue';
import SafetyCheckPanel from '@/components/SafetyCheckPanel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatMg, formatTablets } from '@/lib/dose';
import { index, show } from '@/routes/doctor/patients';
import type { SafetyReport, SelectOption } from '@/types';

type Strength = {
    id: number;
    label: string;
    mg: number;
    divisible: boolean;
};

type DrugOption = {
    id: number;
    code: string;
    name: string;
    regimen: string;
    patient_instruction: string;
    strengths: Strength[];
};

type Kind = 'regular' | 'as_needed';

type Intake = {
    time_of_day: string;
    tablets: number | null;
};

type EditedPrescription = {
    id: number;
    drug_id: number;
    drug_strength_id: number;
    kind: Kind;
    intakes: Intake[];
    tablets: number | null;
    max_daily_doses: number | null;
    instruction: string | null;
    starts_on: string;
    starts_on_label: string;
    ends_on: string | null;
    has_started: boolean;
};

type Props = {
    patient: { id: number; name: string };
    drugs: DrugOption[];
    timesOfDay: SelectOption[];
    limits: {
        intakes: number;
        tablets: number;
        daily_doses: number;
        instruction: number;
    };
    today: string;
    prescription: EditedPrescription | null;
    // Проверка при изменении назначения. Новое назначение проверяется запросом при выборе препарата.
    safety: SafetyReport | null;
    // Итог проверки каждого препарата для выбора в новом назначении, по id препарата.
    drugSafety: Record<number, DrugSafety>;
    // Что уже было с препаратом у пациента: «Уже назначен с …», «Отменён …: причина».
    drugHistory: Record<number, string>;
    justificationLimit: number;
};

type DrugSafety = {
    level: 'contraindicated' | 'caution' | 'clear' | 'no_rules';
    label: string;
    // Почему: данные пациента или препарат, у которого появится находка.
    reason: string | null;
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: Props) => ({
        breadcrumbs: [
            {
                title: 'Пациенты',
                href: index(),
            },
            {
                title: props.patient.name,
                href: show(props.patient.id),
            },
            props.prescription === null
                ? {
                      title: 'Новое назначение',
                      href: PrescriptionController.create(props.patient.id),
                  }
                : {
                      title: 'Изменение назначения',
                      href: PrescriptionController.edit({
                          patient: props.patient.id,
                          prescription: props.prescription.id,
                      }),
                  },
        ],
    }),
});

const editing = props.prescription;
const title = editing === null ? 'Новое назначение' : 'Изменение назначения';

const form = useForm<{
    drug_id: number | null;
    drug_strength_id: number | null;
    kind: Kind;
    intakes: Intake[];
    tablets: number | null;
    max_daily_doses: number | null;
    instruction: string;
    starts_on: string;
    ends_on: string;
    justification: string;
    safety_seen: string[];
}>({
    drug_id: editing?.drug_id ?? null,
    drug_strength_id: editing?.drug_strength_id ?? null,
    kind: editing?.kind ?? 'regular',
    // Время суток врач отмечает сам: отмеченное по умолчанию утро легко принять за схему препарата.
    intakes: editing?.intakes.map((intake) => ({ ...intake })) ?? [],
    tablets: editing?.tablets ?? 1,
    max_daily_doses: editing?.max_daily_doses ?? null,
    instruction: editing?.instruction ?? '',
    starts_on: editing?.starts_on ?? props.today,
    ends_on: editing?.ends_on ?? '',
    justification: '',
    safety_seen: [],
});

// Ошибки приёмов приходят с ключами вида «intakes.0.tablets».
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

// Проверка безопасности: при изменении приходит со страницей, для нового назначения её
// запрашиваем при выборе препарата. Ответ на прежний выбор, пришедший позже, отбрасываем.
// Пока ответа нет, отчёт прежнего препарата не показываем: врач принял бы его за новый.
const report = ref<SafetyReport | null>(props.safety);
const checkFailed = ref(false);
const check = useHttp<{ drug_id: number | null }, SafetyReport>({
    drug_id: null,
});
let checkRequest = 0;

async function runCheck(): Promise<void> {
    const request = ++checkRequest;

    report.value = null;
    checkFailed.value = false;

    if (form.drug_id === null) {
        return;
    }

    check.drug_id = form.drug_id;

    try {
        const result = await check.get(
            SafetyCheckController.show.url(props.patient.id),
        );

        if (request === checkRequest) {
            report.value = result;
        }
    } catch {
        if (request === checkRequest) {
            checkFailed.value = true;
        }
    }
}

// Назначить можно, только когда проверка выбранного препарата на экране.
const checkPending = computed(
    () =>
        check.processing ||
        checkFailed.value ||
        (form.drug_id !== null && report.value === null),
);

watch(
    () => props.safety,
    (value) => {
        report.value = value;
    },
);

// Красные находки без решения: их врач видел, и для них нужно обоснование.
const contraindicationKeys = computed(() =>
    [...(report.value?.findings ?? []), ...(report.value?.caused ?? [])]
        .filter((item) => item.level === 'contraindicated' && !item.decided)
        .map((item) => item.key),
);

// Сервер увидел красную находку, которой не было в блоке: проверяем заново.
watch(
    () => errors.value.safety,
    (message) => {
        if (message && editing === null) {
            void runCheck();
        }
    },
);

const drug = computed(
    () => props.drugs.find((option) => option.id === form.drug_id) ?? null,
);

const strength = computed(
    () =>
        drug.value?.strengths.find(
            (option) => option.id === form.drug_strength_id,
        ) ?? null,
);

// Другой препарат: единственную дозировку выбираем сразу, а инструкцию берём
// из справочника, если врач не менял подставленную.
watch(
    () => form.drug_id,
    (id, previousId) => {
        const next = props.drugs.find((option) => option.id === id);
        const previous = props.drugs.find((option) => option.id === previousId);

        form.clearErrors('drug_id');
        // Обоснование писали для прежнего препарата: к новому оно не относится.
        form.justification = '';
        void runCheck();

        form.drug_strength_id =
            next?.strengths.length === 1 ? next.strengths[0].id : null;

        if (
            form.instruction === '' ||
            form.instruction === previous?.patient_instruction
        ) {
            form.instruction = next?.patient_instruction ?? '';
        }
    },
);

// Ошибка о пересечении с другим назначением стоит у препарата, но зависит и от вида, и от дат.
watch(
    () => [form.kind, form.starts_on, form.ends_on],
    () => form.clearErrors('drug_id'),
);

// Таблетку этой дозировки делить нельзя: половинки сбрасываем, чтобы врач выбрал дозу заново,
// а не получил молча другую.
watch(strength, (value) => {
    if (value !== null) {
        form.clearErrors('drug_strength_id');
    }

    if (value === null || value.divisible) {
        return;
    }

    for (const intake of form.intakes) {
        if (intake.tablets !== null && !Number.isInteger(intake.tablets)) {
            intake.tablets = null;
        }
    }

    if (form.tablets !== null && !Number.isInteger(form.tablets)) {
        form.tablets = null;
    }
});

const tabletOptions = computed(() => {
    const step = strength.value?.divisible ? 0.5 : 1;
    const options: { value: number; label: string }[] = [];

    for (let tablets = step; tablets <= props.limits.tablets; tablets += step) {
        options.push({
            value: tablets,
            label:
                strength.value === null
                    ? formatTablets(tablets)
                    : `${formatTablets(tablets)} (${formatMg(tablets * strength.value.mg)})`,
        });
    }

    return options;
});

// Приёмы в сутки — флажки по времени суток. В форме приёмы лежат по порядку времени суток,
// как их ждёт сервер, а ошибки приходят по номеру приёма.
function intakeOf(timeOfDay: string): Intake | undefined {
    return form.intakes.find((intake) => intake.time_of_day === timeOfDay);
}

function intakeIndex(timeOfDay: string): number {
    return form.intakes.findIndex((intake) => intake.time_of_day === timeOfDay);
}

function intakeErrors(timeOfDay: string): string[] {
    const index = intakeIndex(timeOfDay);

    return index === -1
        ? []
        : [
              errors.value[`intakes.${index}.time_of_day`],
              errors.value[`intakes.${index}.tablets`],
          ].filter((message): message is string => Boolean(message));
}

const intakesFull = computed(() => form.intakes.length >= props.limits.intakes);

function toggleIntake(timeOfDay: string, checked: boolean): void {
    form.clearErrors('intakes');

    if (!checked) {
        form.intakes = form.intakes.filter(
            (intake) => intake.time_of_day !== timeOfDay,
        );

        return;
    }

    const order = props.timesOfDay.map((option) => option.value);

    form.intakes = [
        ...form.intakes,
        { time_of_day: timeOfDay, tablets: 1 },
    ].sort(
        (a, b) => order.indexOf(a.time_of_day) - order.indexOf(b.time_of_day),
    );
}

const kindLabel = computed(() =>
    form.kind === 'regular' ? 'Регулярно' : 'По необходимости',
);

// У начавшегося назначения новая версия действует с сегодняшнего дня.
const endsOnMin = computed(() =>
    editing?.has_started ? props.today : form.starts_on || props.today,
);

// После ответа сервера с ошибками фокус на первом поле с ошибкой: обоснование или поле формы.
async function focusFirstError(): Promise<void> {
    await nextTick();
    document
        .querySelector<HTMLElement>(
            '[data-test="prescription-form"] [aria-invalid="true"]',
        )
        ?.focus();
}

function submit(): void {
    form.safety_seen = contraindicationKeys.value;

    form.submit(
        editing === null
            ? PrescriptionController.store(props.patient.id)
            : PrescriptionController.update({
                  patient: props.patient.id,
                  prescription: editing.id,
              }),
        { onError: () => void focusFirstError() },
    );
}

function safetyOf(drug: DrugOption): DrugSafety | undefined {
    return props.drugSafety[drug.id];
}

// Сводка назначения над кнопкой: врач сверяет её со схемой по инструкции перед сохранением.
const summary = computed(() => {
    if (drug.value === null) {
        return null;
    }

    const name = strength.value
        ? `${drug.value.name} ${strength.value.label}`
        : drug.value.name;

    if (form.kind === 'as_needed') {
        const parts = [name, 'по необходимости'];

        if (form.tablets !== null) {
            parts.push(`${formatTablets(form.tablets)} за приём`);
        }

        if (form.max_daily_doses !== null) {
            parts.push(`не больше ${form.max_daily_doses} в сутки`);
        }

        return parts.join(' · ');
    }

    if (form.intakes.length === 0) {
        return `${name} · время приёма не выбрано`;
    }

    const labels = Object.fromEntries(
        props.timesOfDay.map((option) => [option.value, option.label]),
    );
    const intakes = form.intakes
        .map(
            (intake) =>
                `${labels[intake.time_of_day].toLowerCase()} ${intake.tablets === null ? '?' : formatTablets(intake.tablets)}`,
        )
        .join(', ');
    const daily =
        strength.value &&
        form.intakes.every((intake) => intake.tablets !== null)
            ? ` · ${formatMg(form.intakes.reduce((sum, intake) => sum + (intake.tablets ?? 0), 0) * strength.value.mg)} в сутки`
            : '';

    return `${name} · ${intakes}${daily}`;
});
</script>

<template>
    <Head :title="title" />

    <div class="@container flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
            <p class="text-muted-foreground">Пациент: {{ patient.name }}</p>
        </header>

        <!--
            На широком экране проверка безопасности справа и не уходит при прокрутке. На узком
            она идёт сразу после выбора препарата: колонки растворяются, разделы — по order.
        -->
        <form
            class="grid grid-cols-[minmax(0,1fr)] items-start gap-6 @4xl:grid-cols-[minmax(0,1fr)_24rem]"
            novalidate
            data-test="prescription-form"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-6 @max-4xl:contents">
                <p
                    v-if="editing"
                    class="order-1 rounded-xl border border-dashed bg-card p-4 text-sm text-muted-foreground"
                    data-test="change-notice"
                >
                    <template v-if="editing.has_started">
                        Назначение действует с {{ editing.starts_on_label }}.
                        Изменения вступят в силу сразу, прежний вариант
                        останется в истории.
                    </template>
                    <template v-else>
                        Назначение ещё не началось, изменения сохранятся в нём
                        самом.
                    </template>
                </p>

                <fieldset
                    v-if="editing === null"
                    class="order-1 flex flex-col gap-2"
                    aria-describedby="drug_id-error"
                >
                    <legend class="mb-2 text-sm font-medium">Препарат</legend>
                    <div class="grid gap-2 @2xl:grid-cols-2">
                        <label
                            v-for="option in drugs"
                            :key="option.id"
                            class="flex cursor-pointer items-start gap-3 rounded-lg border border-input bg-card p-3 text-sm transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid"
                            data-test="drug-option"
                            :data-drug="option.code"
                        >
                            <input
                                v-model="form.drug_id"
                                type="radio"
                                name="drug_id"
                                :value="option.id"
                                class="mt-0.5 size-4 shrink-0 outline-none"
                                :aria-invalid="
                                    form.errors.drug_id ? true : undefined
                                "
                            />
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="font-semibold">{{
                                    option.name
                                }}</span>
                                <span
                                    class="flex flex-wrap items-center gap-x-2 text-muted-foreground"
                                >
                                    {{
                                        option.strengths
                                            .map((item) => item.label)
                                            .join(', ')
                                    }}
                                    <span
                                        v-if="
                                            safetyOf(option)?.level ===
                                            'contraindicated'
                                        "
                                        class="flex items-center gap-1 font-medium text-danger"
                                    >
                                        <OctagonAlert class="size-4 shrink-0" />
                                        {{ safetyOf(option)?.label }}
                                    </span>
                                    <span
                                        v-else-if="
                                            safetyOf(option)?.level ===
                                            'caution'
                                        "
                                        class="flex items-center gap-1 font-medium text-warning"
                                    >
                                        <TriangleAlert
                                            class="size-4 shrink-0"
                                        />
                                        {{ safetyOf(option)?.label }}
                                    </span>
                                    <span v-else-if="safetyOf(option)">{{
                                        safetyOf(option)?.label
                                    }}</span>
                                </span>
                                <span
                                    v-if="safetyOf(option)?.reason"
                                    class="text-muted-foreground"
                                    >{{ safetyOf(option)?.reason }}</span
                                >
                                <span
                                    v-if="drugHistory[option.id]"
                                    class="font-medium"
                                    data-test="drug-history"
                                    >{{ drugHistory[option.id] }}</span
                                >
                            </span>
                        </label>
                    </div>
                    <InputError
                        id="drug_id-error"
                        :message="form.errors.drug_id"
                    />
                </fieldset>

                <div v-else class="order-1 flex flex-col gap-1">
                    <span class="text-sm font-medium">Препарат</span>
                    <p class="text-lg font-semibold">{{ drug?.name }}</p>
                </div>

                <div
                    v-if="drug"
                    class="order-3 flex flex-col gap-6 rounded-xl border bg-card p-4 shadow-xs"
                >
                    <!-- Схема из инструкции рядом с приёмами: по ней врач выбирает время и дозу. -->
                    <p
                        class="rounded-lg bg-muted/60 p-3 text-sm"
                        data-test="drug-regimen"
                    >
                        <span class="font-medium">По инструкции:</span>
                        {{ drug.regimen }}
                    </p>

                    <fieldset
                        v-if="drug.strengths.length > 1"
                        class="flex flex-col gap-2"
                        aria-describedby="drug_strength_id-error"
                    >
                        <legend class="mb-2 text-sm font-medium">
                            Дозировка
                        </legend>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="option in drug.strengths"
                                :key="option.id"
                                class="flex cursor-pointer items-center gap-2 rounded-lg border border-input px-3 py-2 text-sm transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid pointer-coarse:min-h-11"
                            >
                                <input
                                    v-model="form.drug_strength_id"
                                    type="radio"
                                    name="drug_strength_id"
                                    :value="option.id"
                                    class="size-4 shrink-0 outline-none"
                                    :aria-invalid="
                                        form.errors.drug_strength_id
                                            ? true
                                            : undefined
                                    "
                                />
                                <span
                                    ><span class="font-medium">{{
                                        option.label
                                    }}</span
                                    ><span class="text-muted-foreground"
                                        >,
                                        {{
                                            option.divisible
                                                ? 'можно делить'
                                                : 'делить нельзя'
                                        }}</span
                                    ></span
                                >
                            </label>
                        </div>
                        <InputError
                            id="drug_strength_id-error"
                            :message="form.errors.drug_strength_id"
                        />
                    </fieldset>
                    <div v-else class="flex flex-col gap-1">
                        <span class="text-sm font-medium">Дозировка</span>
                        <p class="text-sm">
                            {{ drug.strengths[0].label }},
                            {{
                                drug.strengths[0].divisible
                                    ? 'можно делить'
                                    : 'делить нельзя'
                            }}
                        </p>
                        <InputError
                            id="drug_strength_id-error"
                            :message="form.errors.drug_strength_id"
                        />
                    </div>

                    <fieldset
                        class="flex flex-col gap-2"
                        aria-describedby="kind-error"
                    >
                        <legend class="mb-2 text-sm font-medium">
                            Как принимать
                        </legend>
                        <div
                            v-if="editing === null"
                            class="grid grid-cols-2 gap-2 @xl:w-96"
                        >
                            <label
                                v-for="option in [
                                    { value: 'regular', label: 'Регулярно' },
                                    {
                                        value: 'as_needed',
                                        label: 'По необходимости',
                                    },
                                ]"
                                :key="option.value"
                                class="flex cursor-pointer items-center gap-2 rounded-lg border border-input px-3 py-2 text-sm transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid pointer-coarse:min-h-11"
                            >
                                <input
                                    v-model="form.kind"
                                    type="radio"
                                    name="kind"
                                    :value="option.value"
                                    class="size-4 shrink-0 outline-none"
                                    :aria-invalid="
                                        form.errors.kind ? true : undefined
                                    "
                                />
                                {{ option.label }}
                            </label>
                        </div>
                        <p v-else class="text-sm">{{ kindLabel }}</p>
                        <InputError
                            id="kind-error"
                            :message="form.errors.kind"
                        />
                    </fieldset>

                    <fieldset
                        v-if="form.kind === 'regular'"
                        class="flex flex-col gap-2"
                        aria-describedby="intakes-hint intakes-error"
                        data-test="intakes"
                    >
                        <legend class="text-sm font-medium">
                            Приёмы в сутки
                        </legend>
                        <p
                            id="intakes-hint"
                            class="text-sm text-muted-foreground"
                        >
                            Отметьте время суток. Точное время каждого приёма
                            пациент выберет сам.
                        </p>

                        <div
                            v-for="time in timesOfDay"
                            :key="time.value"
                            class="flex flex-col gap-1"
                            data-test="intake"
                        >
                            <div
                                class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border p-2 pl-3"
                                :class="
                                    intakeOf(time.value)
                                        ? 'border-primary/40 bg-accent/40'
                                        : 'border-border'
                                "
                            >
                                <label
                                    class="flex min-h-9 w-36 cursor-pointer items-center gap-2 text-sm font-medium pointer-coarse:min-h-11"
                                >
                                    <input
                                        type="checkbox"
                                        class="size-4 shrink-0"
                                        :checked="
                                            intakeOf(time.value) !== undefined
                                        "
                                        :disabled="
                                            intakeOf(time.value) ===
                                                undefined && intakesFull
                                        "
                                        :aria-invalid="
                                            form.errors.intakes
                                                ? true
                                                : undefined
                                        "
                                        :data-test="`intake-${time.value}`"
                                        @change="
                                            toggleIntake(
                                                time.value,
                                                (
                                                    $event.target as HTMLInputElement
                                                ).checked,
                                            )
                                        "
                                    />
                                    {{ time.label }}
                                </label>
                                <NativeSelect
                                    v-if="intakeOf(time.value)"
                                    v-model="intakeOf(time.value)!.tablets"
                                    :name="`intakes.${intakeIndex(time.value)}.tablets`"
                                    class="min-w-48 flex-1"
                                    :aria-label="`Таблеток, ${time.label.toLowerCase()}`"
                                    :aria-invalid="
                                        intakeErrors(time.value).length > 0
                                            ? true
                                            : undefined
                                    "
                                    :aria-describedby="`intake-${time.value}-error`"
                                >
                                    <option :value="null" disabled>
                                        Сколько таблеток
                                    </option>
                                    <option
                                        v-for="option in tabletOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </NativeSelect>
                            </div>
                            <InputError
                                :id="`intake-${time.value}-error`"
                                :message="intakeErrors(time.value).join(' ')"
                            />
                        </div>

                        <InputError
                            id="intakes-error"
                            :message="form.errors.intakes"
                        />
                    </fieldset>

                    <div v-else class="grid gap-4 @xl:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="tablets">Таблеток за приём</Label>
                            <NativeSelect
                                id="tablets"
                                v-model="form.tablets"
                                name="tablets"
                                :aria-invalid="
                                    form.errors.tablets ? true : undefined
                                "
                                aria-describedby="tablets-error"
                            >
                                <option :value="null" disabled>
                                    Сколько таблеток
                                </option>
                                <option
                                    v-for="option in tabletOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </NativeSelect>
                            <InputError
                                id="tablets-error"
                                :message="form.errors.tablets"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="max_daily_doses"
                                >Раз в сутки, не больше</Label
                            >
                            <NativeSelect
                                id="max_daily_doses"
                                v-model="form.max_daily_doses"
                                name="max_daily_doses"
                                :aria-invalid="
                                    form.errors.max_daily_doses
                                        ? true
                                        : undefined
                                "
                                aria-describedby="max_daily_doses-error"
                            >
                                <option :value="null" disabled>Выберите</option>
                                <option
                                    v-for="count in limits.daily_doses"
                                    :key="count"
                                    :value="count"
                                >
                                    {{ count }}
                                </option>
                            </NativeSelect>
                            <InputError
                                id="max_daily_doses-error"
                                :message="form.errors.max_daily_doses"
                            />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="instruction">Инструкция для пациента</Label>
                        <Textarea
                            id="instruction"
                            v-model="form.instruction"
                            name="instruction"
                            rows="2"
                            :maxlength="limits.instruction"
                            :aria-invalid="
                                form.errors.instruction ? true : undefined
                            "
                            aria-describedby="instruction-hint instruction-error"
                        />
                        <p
                            id="instruction-hint"
                            class="text-sm text-muted-foreground"
                        >
                            Подставляется из справочника, её можно изменить.
                        </p>
                        <InputError
                            id="instruction-error"
                            :message="form.errors.instruction"
                        />
                    </div>

                    <div class="flex flex-col gap-2">
                        <div class="grid gap-4 @xl:grid-cols-2">
                            <div class="grid content-start gap-2">
                                <span
                                    v-if="editing?.has_started"
                                    class="text-sm leading-none font-medium"
                                    >Дата начала</span
                                >
                                <Label v-else for="starts_on"
                                    >Дата начала</Label
                                >
                                <p v-if="editing?.has_started" class="text-sm">
                                    {{ editing.starts_on_label }}
                                </p>
                                <Input
                                    v-else
                                    id="starts_on"
                                    v-model="form.starts_on"
                                    name="starts_on"
                                    type="date"
                                    :min="today"
                                    required
                                    :aria-invalid="
                                        form.errors.starts_on ? true : undefined
                                    "
                                    aria-describedby="starts_on-error"
                                />
                                <InputError
                                    id="starts_on-error"
                                    :message="form.errors.starts_on"
                                />
                            </div>
                            <div class="grid content-start gap-2">
                                <Label for="ends_on"
                                    >Дата окончания (необязательно)</Label
                                >
                                <Input
                                    id="ends_on"
                                    v-model="form.ends_on"
                                    name="ends_on"
                                    type="date"
                                    :min="endsOnMin"
                                    :aria-invalid="
                                        form.errors.ends_on ? true : undefined
                                    "
                                    aria-describedby="ends_on-error"
                                />
                                <InputError
                                    id="ends_on-error"
                                    :message="form.errors.ends_on"
                                />
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Даты считаются по часовому поясу пациента.
                        </p>
                    </div>
                </div>
            </div>

            <div
                class="order-2 flex flex-col gap-2 @4xl:sticky @4xl:top-18 @4xl:max-h-[calc(100svh-10rem)] @4xl:overflow-y-auto"
            >
                <SafetyCheckPanel
                    :report="report"
                    :loading="check.processing"
                    :failed="checkFailed"
                    :drug="drug?.name ?? null"
                    @retry="runCheck"
                >
                    <p
                        v-if="errors.safety"
                        role="alert"
                        class="rounded-lg border border-danger-border bg-danger-subtle p-3 text-sm font-medium text-danger"
                        data-test="safety-error"
                    >
                        {{ errors.safety }}
                    </p>

                    <div
                        v-if="report?.requires_justification"
                        class="grid gap-2 border-t pt-3"
                        data-test="justification"
                    >
                        <Label for="justification" class="leading-snug"
                            >Обоснование назначения вопреки
                            предупреждению</Label
                        >
                        <Textarea
                            id="justification"
                            v-model="form.justification"
                            name="justification"
                            rows="3"
                            required
                            :maxlength="justificationLimit"
                            :aria-invalid="
                                form.errors.justification ? true : undefined
                            "
                            aria-describedby="justification-hint justification-error"
                        />
                        <p
                            id="justification-hint"
                            class="text-sm text-muted-foreground"
                        >
                            Обязательно при противопоказании. Сохранится в
                            находке и в журнале аудита.
                        </p>
                        <InputError
                            id="justification-error"
                            :message="form.errors.justification"
                        />
                    </div>
                </SafetyCheckPanel>
            </div>
            <!-- Сводка и кнопки закреплены внизу: обоснование справа и «Назначить» видны вместе. -->
            <div
                class="sticky bottom-0 z-10 order-5 -mx-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t bg-background/95 px-4 py-3 backdrop-blur-sm md:-mx-6 md:px-6 @4xl:col-span-2"
                data-test="prescription-summary"
            >
                <p
                    v-if="summary"
                    class="min-w-0 flex-1 text-sm font-medium max-sm:line-clamp-2"
                >
                    {{ summary }}
                    <span
                        v-if="
                            report?.requires_justification &&
                            form.justification.trim() === ''
                        "
                        class="text-danger"
                        >· нужно обоснование</span
                    >
                </p>
                <div class="ml-auto flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="show(patient.id)">Отмена</Link>
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing || checkPending"
                        :aria-busy="form.processing"
                        data-test="save-prescription"
                    >
                        <Spinner v-if="form.processing" />
                        {{
                            editing === null
                                ? 'Назначить'
                                : 'Сохранить изменения'
                        }}
                    </Button>
                </div>
            </div>
        </form>
    </div>
</template>
