<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import OwnMedicationController from '@/actions/App/Http/Controllers/Patient/OwnMedicationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { medications } from '@/routes/patient';

type Kind = 'regular' | 'as_needed';

// Время суток с подсказкой точного времени: утром 8:00 и так далее.
type TimeOfDayOption = {
    value: string;
    label: string;
    time: string;
};

type Intake = {
    time_of_day: string;
    time: string;
};

type EditedMedication = {
    id: number;
    drug_name: string;
    kind: Kind;
    dose: string;
    intakes: Intake[];
    max_daily_doses: number | null;
    starts_on: string;
    starts_on_label: string;
    ends_on: string | null;
    has_started: boolean;
    prescribed_by: string | null;
};

type Props = {
    drugs: { id: number; name: string }[];
    timesOfDay: TimeOfDayOption[];
    limits: {
        intakes: number;
        daily_doses: number;
        drug_name: number;
        dose: number;
        prescribed_by: number;
    };
    today: string;
    timezone: string | null;
    prescription: EditedMedication | null;
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: Props) => ({
        breadcrumbs: [
            {
                title: 'Мои препараты',
                href: medications(),
            },
            props.prescription === null
                ? {
                      title: 'Новый препарат',
                      href: OwnMedicationController.create(),
                  }
                : {
                      title: 'Изменение препарата',
                      href: OwnMedicationController.edit(props.prescription.id),
                  },
        ],
    }),
});

const editing = props.prescription;
const title = editing === null ? 'Добавить препарат' : 'Изменить препарат';

const form = useForm<{
    // Препарат из справочника или «другой» с названием текстом.
    drug_id: number | 'other' | null;
    drug_name: string;
    dose: string;
    kind: Kind;
    intakes: Intake[];
    max_daily_doses: number | null;
    starts_on: string;
    ends_on: string;
    prescribed_by: string;
}>({
    drug_id: null,
    drug_name: '',
    dose: editing?.dose ?? '',
    kind: editing?.kind ?? 'regular',
    intakes: editing?.intakes.map((intake) => ({ ...intake })) ?? [
        {
            time_of_day: props.timesOfDay[0].value,
            time: props.timesOfDay[0].time,
        },
    ],
    max_daily_doses: editing?.max_daily_doses ?? null,
    starts_on: editing?.starts_on ?? props.today,
    ends_on: editing?.ends_on ?? '',
    prescribed_by: editing?.prescribed_by ?? '',
});

// Ошибки приёмов приходят с ключами вида «intakes.0.time».
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

// После правки поля прежняя ошибка уже не относится к введённому. Ошибка о назначении
// врача зависит и от вида, и от дат.
watch(
    () => [form.drug_id, form.drug_name, form.starts_on, form.ends_on],
    () => form.clearErrors('drug_id', 'drug_name', 'starts_on', 'ends_on'),
);
watch(
    () => form.kind,
    () => form.clearErrors('drug_id'),
);
watch(
    () => form.dose,
    () => form.clearErrors('dose'),
);
// Ошибка о совпадающем времени стоит у обоих приёмов: после правки любого приёма сбрасываем все.
watch(
    () => JSON.stringify(form.intakes),
    () => {
        for (const key of Object.keys(form.errors)) {
            if (key.startsWith('intakes')) {
                form.clearErrors(key as 'intakes');
            }
        }
    },
);

function defaultTime(timeOfDay: string): string {
    return (
        props.timesOfDay.find((option) => option.value === timeOfDay)?.time ??
        ''
    );
}

// Время, которое пациент ещё не менял, следует за временем суток: «Вечером» — 20:00.
function setTimeOfDay(intake: Intake, timeOfDay: unknown): void {
    if (intake.time === defaultTime(intake.time_of_day)) {
        intake.time = defaultTime(String(timeOfDay));
    }

    intake.time_of_day = String(timeOfDay);
}

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
        form.intakes.push({ time_of_day: free.value, time: free.time });
    }
}

function removeIntake(position: number): void {
    form.intakes.splice(position, 1);
}

const kindLabel = computed(() =>
    form.kind === 'regular' ? 'Регулярно' : 'По необходимости',
);

// У начатого препарата новая версия действует с сегодняшнего дня.
const endsOnMin = computed(() =>
    editing?.has_started ? props.today : form.starts_on || props.today,
);

function submit(): void {
    form.submit(
        editing === null
            ? OwnMedicationController.store()
            : OwnMedicationController.update(editing.id),
    );
}
</script>

<template>
    <Head :title="title" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            :title="title"
            description="Препарат, который назначил другой врач. Вы будете отмечать его приём, а ваш врач увидит, что вы его принимаете."
        />

        <p
            v-if="editing"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
            data-test="change-notice"
        >
            <template v-if="editing.has_started">
                Вы принимаете препарат с {{ editing.starts_on_label }}.
                Изменения вступят в силу сразу.
            </template>
            <template v-else>
                Приём ещё не начался, изменения сохранятся в нём самом.
            </template>
        </p>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label v-if="editing === null" for="drug_id">Препарат</Label>
                <!-- При изменении вместо поля текст, и подпись тоже текст. -->
                <span v-else class="text-sm leading-none font-medium"
                    >Препарат</span
                >
                <template v-if="editing === null">
                    <NativeSelect
                        id="drug_id"
                        v-model="form.drug_id"
                        name="drug_id"
                    >
                        <option :value="null" disabled>
                            Выберите препарат
                        </option>
                        <option
                            v-for="option in drugs"
                            :key="option.id"
                            :value="option.id"
                        >
                            {{ option.name }}
                        </option>
                        <option value="other">Другой препарат</option>
                    </NativeSelect>
                    <InputError :message="form.errors.drug_id" />
                </template>
                <template v-else>
                    <p class="text-sm font-medium">{{ editing.drug_name }}</p>
                    <InputError :message="form.errors.drug_id" />
                </template>
            </div>

            <div v-if="form.drug_id === 'other'" class="grid gap-2">
                <Label for="drug_name">Название препарата</Label>
                <Input
                    id="drug_name"
                    v-model="form.drug_name"
                    name="drug_name"
                    :maxlength="limits.drug_name"
                    placeholder="Как написано на упаковке"
                />
                <InputError :message="form.errors.drug_name" />
            </div>

            <div class="grid gap-2">
                <Label for="dose">Доза</Label>
                <Input
                    id="dose"
                    v-model="form.dose"
                    name="dose"
                    :maxlength="limits.dose"
                    placeholder="Например, 1 таблетка 50 мг"
                />
                <InputError :message="form.errors.dose" />
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

                <div
                    v-for="(intake, position) in form.intakes"
                    :key="position"
                    class="flex flex-col gap-1"
                    data-test="intake"
                >
                    <div class="flex items-center gap-2">
                        <NativeSelect
                            :model-value="intake.time_of_day"
                            :name="`intakes.${position}.time_of_day`"
                            :aria-label="`Время суток, приём ${position + 1}`"
                            class="min-w-0 flex-1"
                            @update:model-value="
                                (value) => setTimeOfDay(intake, value)
                            "
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
                        <Input
                            :id="`intake_time_${position}`"
                            v-model="intake.time"
                            :name="`intakes.${position}.time`"
                            :aria-label="`Время, приём ${position + 1}`"
                            type="time"
                            class="w-32 shrink-0"
                            required
                        />
                        <Button
                            v-if="form.intakes.length > 1"
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="shrink-0"
                            aria-label="Убрать приём"
                            @click="removeIntake(position)"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                    <InputError
                        :message="errors[`intakes.${position}.time_of_day`]"
                    />
                    <InputError :message="errors[`intakes.${position}.time`]" />
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

                <p class="text-sm text-muted-foreground">
                    Время по вашему часовому поясу<template v-if="timezone"
                        >: {{ timezone }}</template
                    >.
                </p>
            </fieldset>

            <div v-else class="grid gap-2">
                <Label for="max_daily_doses">
                    Сколько раз в сутки, не больше (необязательно)
                </Label>
                <NativeSelect
                    id="max_daily_doses"
                    v-model="form.max_daily_doses"
                    name="max_daily_doses"
                >
                    <option :value="null">Не указано</option>
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

            <div class="grid gap-2">
                <Label for="prescribed_by">Кто назначил (необязательно)</Label>
                <Input
                    id="prescribed_by"
                    v-model="form.prescribed_by"
                    name="prescribed_by"
                    :maxlength="limits.prescribed_by"
                    placeholder="Например, терапевт Петрова И. А."
                />
                <InputError :message="form.errors.prescribed_by" />
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="save-medication"
                >
                    {{ editing === null ? 'Добавить' : 'Сохранить изменения' }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="medications()">Отмена</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
