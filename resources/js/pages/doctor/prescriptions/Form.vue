<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import PrescriptionController from '@/actions/App/Http/Controllers/Doctor/PrescriptionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { formatMg, formatTablets } from '@/lib/dose';
import { index, show } from '@/routes/doctor/patients';
import type { SelectOption } from '@/types';

type Strength = {
    id: number;
    label: string;
    mg: number;
    divisible: boolean;
};

type DrugOption = {
    id: number;
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
}>({
    drug_id: editing?.drug_id ?? null,
    drug_strength_id: editing?.drug_strength_id ?? null,
    kind: editing?.kind ?? 'regular',
    intakes: editing?.intakes.map((intake) => ({ ...intake })) ?? [
        { time_of_day: props.timesOfDay[0].value, tablets: 1 },
    ],
    tablets: editing?.tablets ?? 1,
    max_daily_doses: editing?.max_daily_doses ?? null,
    instruction: editing?.instruction ?? '',
    starts_on: editing?.starts_on ?? props.today,
    ends_on: editing?.ends_on ?? '',
});

// Ошибки приёмов приходят с ключами вида «intakes.0.tablets».
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
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

const usedTimes = computed(
    () => new Set(form.intakes.map((intake) => intake.time_of_day)),
);

const canAddIntake = computed(
    () =>
        form.intakes.length < props.limits.intakes &&
        form.intakes.length < props.timesOfDay.length,
);

function addIntake(): void {
    const free = props.timesOfDay.find(
        (option) => !usedTimes.value.has(option.value),
    );

    if (free) {
        form.intakes.push({ time_of_day: free.value, tablets: 1 });
    }
}

function removeIntake(position: number): void {
    form.intakes.splice(position, 1);
}

const kindLabel = computed(() =>
    form.kind === 'regular' ? 'Регулярно' : 'По необходимости',
);

// У начавшегося назначения новая версия действует с сегодняшнего дня.
const endsOnMin = computed(() =>
    editing?.has_started ? props.today : form.starts_on || props.today,
);

function submit(): void {
    form.submit(
        editing === null
            ? PrescriptionController.store(props.patient.id)
            : PrescriptionController.update({
                  patient: props.patient.id,
                  prescription: editing.id,
              }),
    );
}
</script>

<template>
    <Head :title="title" />

    <div class="flex max-w-2xl flex-col gap-6 p-4">
        <Heading :title="title" :description="patient.name" />

        <p
            v-if="editing"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
            data-test="change-notice"
        >
            <template v-if="editing.has_started">
                Назначение действует с {{ editing.starts_on_label }}. Изменения
                вступят в силу сразу, прежний вариант останется в истории.
            </template>
            <template v-else>
                Назначение ещё не началось, изменения сохранятся в нём самом.
            </template>
        </p>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label v-if="editing === null" for="drug_id">Препарат</Label>
                <!-- При изменении вместо поля текст, и подпись тоже текст. -->
                <span v-else class="text-sm leading-none font-medium"
                    >Препарат</span
                >
                <NativeSelect
                    v-if="editing === null"
                    id="drug_id"
                    v-model="form.drug_id"
                    name="drug_id"
                >
                    <option :value="null" disabled>Выберите препарат</option>
                    <option
                        v-for="option in drugs"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.name }}
                    </option>
                </NativeSelect>
                <p v-else class="text-sm font-medium">{{ drug?.name }}</p>
                <p v-if="drug" class="text-sm text-muted-foreground">
                    По инструкции: {{ drug.regimen }}
                </p>
                <InputError :message="form.errors.drug_id" />
            </div>

            <div v-if="drug" class="grid gap-2">
                <Label for="drug_strength_id">Дозировка</Label>
                <NativeSelect
                    id="drug_strength_id"
                    v-model="form.drug_strength_id"
                    name="drug_strength_id"
                >
                    <option :value="null" disabled>Выберите дозировку</option>
                    <option
                        v-for="option in drug.strengths"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.label }},
                        {{
                            option.divisible ? 'можно делить' : 'делить нельзя'
                        }}
                    </option>
                </NativeSelect>
                <InputError :message="form.errors.drug_strength_id" />
            </div>

            <fieldset class="flex flex-col gap-2">
                <legend class="mb-2 text-sm font-medium">Как принимать</legend>
                <template v-if="editing === null">
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="form.kind"
                            type="radio"
                            name="kind"
                            value="regular"
                        />
                        Регулярно
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="form.kind"
                            type="radio"
                            name="kind"
                            value="as_needed"
                        />
                        По необходимости
                    </label>
                </template>
                <p v-else class="text-sm">{{ kindLabel }}</p>
                <InputError :message="form.errors.kind" />
            </fieldset>

            <fieldset
                v-if="form.kind === 'regular'"
                class="flex flex-col gap-3"
                data-test="intakes"
            >
                <legend class="mb-1 text-sm font-medium">Приёмы в сутки</legend>
                <p class="text-sm text-muted-foreground">
                    Точное время каждого приёма пациент выберет сам.
                </p>

                <div
                    v-for="(intake, position) in form.intakes"
                    :key="position"
                    class="flex flex-col gap-1"
                    data-test="intake"
                >
                    <!-- На телефоне время суток и доза идут друг под другом, иначе подпись дозы не помещается. -->
                    <div
                        class="flex items-start gap-2 rounded-md border p-2 sm:border-0 sm:p-0"
                    >
                        <div class="grid flex-1 gap-2 sm:grid-cols-[10rem_1fr]">
                            <NativeSelect
                                v-model="intake.time_of_day"
                                :name="`intakes.${position}.time_of_day`"
                                :aria-label="`Время суток, приём ${position + 1}`"
                            >
                                <option
                                    v-for="option in timesOfDay"
                                    :key="option.value"
                                    :value="option.value"
                                    :disabled="
                                        option.value !== intake.time_of_day &&
                                        usedTimes.has(option.value)
                                    "
                                >
                                    {{ option.label }}
                                </option>
                            </NativeSelect>
                            <NativeSelect
                                v-model="intake.tablets"
                                :name="`intakes.${position}.tablets`"
                                :aria-label="`Таблеток, приём ${position + 1}`"
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
                        <Button
                            v-if="form.intakes.length > 1"
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Убрать приём"
                            @click="removeIntake(position)"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                    <InputError
                        :message="errors[`intakes.${position}.time_of_day`]"
                    />
                    <InputError
                        :message="errors[`intakes.${position}.tablets`]"
                    />
                </div>

                <InputError :message="form.errors.intakes" />

                <Button
                    v-if="canAddIntake"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="self-start"
                    data-test="add-intake"
                    @click="addIntake"
                >
                    <Plus />
                    Добавить приём
                </Button>
            </fieldset>

            <div v-else class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="tablets">Таблеток за приём</Label>
                    <NativeSelect
                        id="tablets"
                        v-model="form.tablets"
                        name="tablets"
                    >
                        <option :value="null" disabled>Сколько таблеток</option>
                        <option
                            v-for="option in tabletOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.tablets" />
                </div>
                <div class="grid gap-2">
                    <Label for="max_daily_doses">Раз в сутки, не больше</Label>
                    <NativeSelect
                        id="max_daily_doses"
                        v-model="form.max_daily_doses"
                        name="max_daily_doses"
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
                    <InputError :message="form.errors.max_daily_doses" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="instruction">Инструкция для пациента</Label>
                <Textarea
                    id="instruction"
                    v-model="form.instruction"
                    name="instruction"
                    rows="3"
                    :maxlength="limits.instruction"
                />
                <p class="text-sm text-muted-foreground">
                    Подставляется из справочника, её можно изменить.
                </p>
                <InputError :message="form.errors.instruction" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid content-start gap-2">
                    <span
                        v-if="editing?.has_started"
                        class="text-sm leading-none font-medium"
                        >Дата начала</span
                    >
                    <Label v-else for="starts_on">Дата начала</Label>
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
                    />
                    <InputError :message="form.errors.starts_on" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="ends_on">Дата окончания (необязательно)</Label>
                    <Input
                        id="ends_on"
                        v-model="form.ends_on"
                        name="ends_on"
                        type="date"
                        :min="endsOnMin"
                    />
                    <InputError :message="form.errors.ends_on" />
                </div>
            </div>
            <p class="-mt-4 text-sm text-muted-foreground">
                Даты считаются по часовому поясу пациента.
            </p>

            <div class="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="save-prescription"
                >
                    {{ editing === null ? 'Назначить' : 'Сохранить изменения' }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="show(patient.id)">Отмена</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
