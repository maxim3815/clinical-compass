<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ChevronDown, Info, Plus, Trash2 } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import OwnMedicationController from '@/actions/App/Http/Controllers/Patient/OwnMedicationController';
import InputError from '@/components/InputError.vue';
import LeaveConfirmDialog from '@/components/LeaveConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
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

const kinds = [
    { value: 'regular', label: 'Регулярно' },
    { value: 'as_needed', label: 'По необходимости' },
] as const;

const kindLabel = computed(() =>
    form.kind === 'regular' ? 'Регулярно' : 'По необходимости',
);

// У начатого препарата новая версия действует с сегодняшнего дня.
const endsOnMin = computed(() =>
    editing?.has_started ? props.today : form.starts_on || props.today,
);

// Дата окончания и «кто назначил» необязательны: свёрнуты, пока пустые и без ошибок.
const showOptional = ref(
    Boolean(editing?.ends_on) || Boolean(editing?.prescribed_by),
);

watch(
    () => [form.errors.ends_on, form.errors.prescribed_by],
    ([endsOn, prescribedBy]) => {
        if (endsOn || prescribedBy) {
            showOptional.value = true;
        }
    },
);

const formElement = useTemplateRef<HTMLFormElement>('formElement');

// Форма длинная: после ошибок фокус переходит к первому полю с ошибкой.
function submit(): void {
    form.submit(
        editing === null
            ? OwnMedicationController.store()
            : OwnMedicationController.update(editing.id),
        {
            onError: async () => {
                await nextTick();
                formElement.value
                    ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                    ?.focus();
            },
        },
    );
}

// Уход с заполненной формы переспрашивает: панели вкладок здесь нет, но ссылка назад в шапке есть.
const leaveGuard = useLeaveGuard(() => form.isDirty && !form.processing);
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-6 p-4">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
            <p class="text-muted-foreground">
                Препарат, который назначил другой врач. Вы будете отмечать его
                приём, а ваш врач увидит, что вы его принимаете.
            </p>
        </header>

        <p
            v-if="editing"
            class="flex gap-3 rounded-xl bg-muted p-3"
            data-test="change-notice"
        >
            <Info class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
            <span v-if="editing.has_started">
                Вы принимаете препарат с {{ editing.starts_on_label }}.
                Изменения вступят в силу сразу.
            </span>
            <span v-else>
                Приём ещё не начался, изменения сохранятся в нём самом.
            </span>
        </p>

        <form
            ref="formElement"
            class="flex flex-col gap-6 rounded-2xl border bg-card p-4 shadow-xs"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label v-if="editing === null" for="drug_id">Препарат</Label>
                <!-- При изменении вместо поля текст, и подпись тоже текст. -->
                <span v-else class="leading-none font-medium">Препарат</span>
                <template v-if="editing === null">
                    <NativeSelect
                        id="drug_id"
                        v-model="form.drug_id"
                        name="drug_id"
                        :aria-invalid="form.errors.drug_id ? true : undefined"
                        aria-describedby="drug_id-error"
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
                    <InputError
                        id="drug_id-error"
                        :message="form.errors.drug_id"
                    />
                </template>
                <template v-else>
                    <p class="text-lg font-semibold">{{ editing.drug_name }}</p>
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
                    :aria-invalid="form.errors.drug_name ? true : undefined"
                    aria-describedby="drug_name-error"
                />
                <InputError
                    id="drug_name-error"
                    :message="form.errors.drug_name"
                />
            </div>

            <div class="grid gap-2">
                <Label for="dose">Доза</Label>
                <Input
                    id="dose"
                    v-model="form.dose"
                    name="dose"
                    :maxlength="limits.dose"
                    placeholder="Например, 1 таблетка 50 мг"
                    :aria-invalid="form.errors.dose ? true : undefined"
                    aria-describedby="dose-error"
                />
                <InputError id="dose-error" :message="form.errors.dose" />
            </div>

            <fieldset class="flex flex-col gap-2" aria-describedby="kind-error">
                <legend class="mb-2 font-medium">Как принимать</legend>
                <div v-if="editing === null" class="grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="option in kinds"
                        :key="option.value"
                        class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-input bg-card px-3 py-2 transition-colors has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid"
                    >
                        <input
                            v-model="form.kind"
                            type="radio"
                            name="kind"
                            :value="option.value"
                            class="size-5 shrink-0 outline-none"
                            :aria-invalid="form.errors.kind ? true : undefined"
                        />
                        {{ option.label }}
                    </label>
                </div>
                <p v-else>{{ kindLabel }}</p>
                <InputError id="kind-error" :message="form.errors.kind" />
            </fieldset>

            <fieldset
                v-if="form.kind === 'regular'"
                class="flex flex-col gap-3"
                data-test="intakes"
            >
                <legend class="mb-2 font-medium">Приёмы в сутки</legend>

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
                            :aria-invalid="
                                errors[`intakes.${position}.time_of_day`]
                                    ? true
                                    : undefined
                            "
                            :aria-describedby="`intake_${position}-errors intakes-error`"
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
                            :aria-invalid="
                                errors[`intakes.${position}.time`]
                                    ? true
                                    : undefined
                            "
                            :aria-describedby="`intake_${position}-errors intakes-error`"
                            type="time"
                            class="w-36 shrink-0"
                            required
                        />
                        <Button
                            v-if="form.intakes.length > 1"
                            type="button"
                            variant="ghost"
                            size="icon-lg"
                            class="shrink-0"
                            :aria-label="`Убрать приём ${position + 1}`"
                            @click="removeIntake(position)"
                        >
                            <Trash2 class="size-5" />
                        </Button>
                    </div>
                    <div :id="`intake_${position}-errors`">
                        <InputError
                            :message="errors[`intakes.${position}.time_of_day`]"
                        />
                        <InputError
                            :message="errors[`intakes.${position}.time`]"
                        />
                    </div>
                </div>

                <InputError id="intakes-error" :message="form.errors.intakes" />

                <Button
                    v-if="canAddIntake"
                    type="button"
                    variant="outline"
                    size="lg"
                    class="self-start"
                    data-test="add-intake"
                    @click="addIntake"
                >
                    <Plus />
                    Добавить приём
                </Button>

                <p class="text-muted-foreground">
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
                    :aria-invalid="
                        form.errors.max_daily_doses ? true : undefined
                    "
                    aria-describedby="max_daily_doses-error"
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
                <InputError
                    id="max_daily_doses-error"
                    :message="form.errors.max_daily_doses"
                />
            </div>

            <div class="grid gap-2">
                <span
                    v-if="editing?.has_started"
                    class="leading-none font-medium"
                    >Дата начала</span
                >
                <Label v-else for="starts_on">Дата начала</Label>
                <p v-if="editing?.has_started">
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
                    :aria-invalid="form.errors.starts_on ? true : undefined"
                    aria-describedby="starts_on-error"
                />
                <InputError
                    id="starts_on-error"
                    :message="form.errors.starts_on"
                />
            </div>

            <div class="flex flex-col gap-4">
                <Button
                    type="button"
                    variant="ghost"
                    size="lg"
                    class="-ml-3 self-start px-3 text-primary"
                    :aria-expanded="showOptional"
                    aria-controls="optional-fields"
                    data-test="optional-fields-button"
                    @click="showOptional = !showOptional"
                >
                    <ChevronDown
                        class="size-5 transition-transform"
                        :class="{ 'rotate-180': showOptional }"
                    />
                    Дата окончания и кто назначил
                </Button>

                <div
                    v-show="showOptional"
                    id="optional-fields"
                    class="flex flex-col gap-6"
                >
                    <div class="grid gap-2">
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

                    <div class="grid gap-2">
                        <Label for="prescribed_by"
                            >Кто назначил (необязательно)</Label
                        >
                        <Input
                            id="prescribed_by"
                            v-model="form.prescribed_by"
                            name="prescribed_by"
                            :maxlength="limits.prescribed_by"
                            placeholder="Например, терапевт Петрова И. А."
                            :aria-invalid="
                                form.errors.prescribed_by ? true : undefined
                            "
                            aria-describedby="prescribed_by-error"
                        />
                        <InputError
                            id="prescribed_by-error"
                            :message="form.errors.prescribed_by"
                        />
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button
                    type="submit"
                    size="lg"
                    :disabled="form.processing"
                    :aria-busy="form.processing"
                    data-test="save-medication"
                >
                    <Spinner v-if="form.processing" class="size-5" />
                    {{ editing === null ? 'Добавить' : 'Сохранить изменения' }}
                </Button>
                <Button variant="outline" size="lg" as-child>
                    <Link :href="medications()">Отмена</Link>
                </Button>
            </div>
        </form>

        <LeaveConfirmDialog
            :pending="leaveGuard.pending.value"
            @leave="leaveGuard.leave"
            @stay="leaveGuard.stay"
        />
    </div>
</template>
