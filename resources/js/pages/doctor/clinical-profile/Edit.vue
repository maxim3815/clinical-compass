<script setup lang="ts">
import { Head, Link, router, useForm, useHttp } from '@inertiajs/vue3';
import { ArrowLeft, Search } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import ClinicalProfileController from '@/actions/App/Http/Controllers/Doctor/ClinicalProfileController';
import Icd10Controller from '@/actions/App/Http/Controllers/Doctor/Icd10Controller';
import InputError from '@/components/InputError.vue';
import ObservationForm from '@/components/ObservationForm.vue';
import type {
    ObservationDraft,
    ObservationTypeOption,
} from '@/components/ObservationForm.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, show } from '@/routes/doctor/patients';
import type { ClinicalDiagnosis, ClinicalProfile, SelectOption } from '@/types';

type Props = {
    patient: { id: number; name: string };
    profile: ClinicalProfile & {
        resolved_diagnoses: ClinicalDiagnosis[];
        types: ObservationTypeOption[];
    };
    sexOptions: SelectOption[];
    today: string;
    limits: { comment: number };
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: Props) => ({
        breadcrumbs: [
            { title: 'Пациенты', href: index() },
            { title: props.patient.name, href: show(props.patient.id) },
            {
                title: 'Клинический профиль',
                href: ClinicalProfileController.edit(props.patient.id),
            },
        ],
    }),
});

const sexForm = useForm<{ sex: string }>({ sex: props.profile.sex ?? '' });

function saveSex(): void {
    sexForm.submit(ClinicalProfileController.updateSex(props.patient.id), {
        preserveScroll: true,
    });
}

// Беременность и лактация вносятся только у пациентки.
const types = computed(() =>
    props.profile.types.filter(
        (type) => !type.female_only || props.profile.sex === 'female',
    ),
);
const hiddenFemaleOnly = computed(() =>
    props.profile.types
        .filter((type) => type.female_only && props.profile.sex !== 'female')
        .map((type) => type.name.toLowerCase()),
);

// Новые значения фактов: врач отмечает их в нескольких строках и сохраняет одной кнопкой.
// Каждое значение сохраняется отдельной записью, прежние остаются в истории.
const emptyDraft = (): ObservationDraft => ({
    value: '',
    comment: '',
    observed_on: props.today,
});
const drafts = reactive<Record<number, ObservationDraft>>(
    Object.fromEntries(
        props.profile.types.map((type) => [type.id, emptyDraft()]),
    ),
);
const changed = computed(() =>
    types.value.filter((type) => drafts[type.id].value !== ''),
);

const factsForm = useForm<{
    observations: (ObservationDraft & { observation_type_id: number })[];
}>({ observations: [] });

// Ошибки приходят по номеру в отправленном списке: «observations.1.value».
let submitted: number[] = [];

function factErrors(
    typeId: number,
): Partial<Record<'value' | 'comment' | 'observed_on', string>> {
    const index = submitted.indexOf(typeId);
    const errors = factsForm.errors as Record<string, string | undefined>;

    if (index === -1) {
        return {};
    }

    return {
        value:
            errors[`observations.${index}.value`] ??
            errors[`observations.${index}.observation_type_id`],
        comment: errors[`observations.${index}.comment`],
        observed_on: errors[`observations.${index}.observed_on`],
    };
}

function saveFacts(): void {
    submitted = changed.value.map((type) => type.id);
    factsForm.observations = changed.value.map((type) => ({
        observation_type_id: type.id,
        ...drafts[type.id],
    }));

    factsForm.submit(
        ClinicalProfileController.storeObservations(props.patient.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                submitted = [];

                for (const type of props.profile.types) {
                    drafts[type.id] = emptyDraft();
                }
            },
            onError: async () => {
                await nextTick();
                document
                    .querySelector<HTMLElement>(
                        '[data-test="facts-section"] [aria-invalid="true"]',
                    )
                    ?.focus();
            },
        },
    );
}

// «Беременность — да, СКФ — 48 мл/мин/1,73 м²»: врач видит, что именно не сохранено.
function draftLabel(type: ObservationTypeOption): string {
    const value = drafts[type.id].value;
    const shown =
        type.value_type === 'boolean'
            ? value === '1'
                ? 'да'
                : 'нет'
            : type.value_type === 'number'
              ? `${value} ${type.unit ?? ''}`.trim()
              : (type.options?.find((option) => option.value === value)
                    ?.label ?? value);

    return `${type.name} — ${shown}`;
}

function focusFact(type: ObservationTypeOption): void {
    document
        .querySelector<HTMLElement>(
            `[data-fact="${type.code}"] input, [data-fact="${type.code}"] select`,
        )
        ?.focus();
}

function resetFacts(): void {
    if (
        changed.value.length > 1 &&
        !window.confirm('Сбросить все отмеченные значения?')
    ) {
        return;
    }

    for (const type of props.profile.types) {
        drafts[type.id] = emptyDraft();
    }

    factsForm.clearErrors();
}

// Уход со страницы с несохранёнными значениями переспрашивает: иначе они пропадут молча.
const removeLeaveGuard = router.on('before', (event) => {
    if (
        changed.value.length > 0 &&
        event.detail.visit.method === 'get' &&
        !window.confirm(
            'Новые значения фактов не сохранены. Уйти со страницы без сохранения?',
        )
    ) {
        event.preventDefault();
    }
});

// Закрытие вкладки и перезагрузка тоже переспрашивают.
function warnBeforeUnload(event: BeforeUnloadEvent): void {
    if (changed.value.length > 0) {
        event.preventDefault();
    }
}

onMounted(() => window.addEventListener('beforeunload', warnBeforeUnload));

onBeforeUnmount(() => {
    removeLeaveGuard();
    window.removeEventListener('beforeunload', warnBeforeUnload);
});

// Диагноз выбирается из справочника МКБ-10: поиск по коду или названию.
const diagnosisForm = useForm<{
    icd10_code: string;
    is_main: boolean;
    established_on: string;
}>({
    icd10_code: '',
    is_main: props.profile.diagnoses.every((diagnosis) => !diagnosis.is_main),
    established_on: props.today,
});

const search = ref('');
const chosen = ref<{ code: string; name: string } | null>(null);
const lookup = useHttp<
    { search: string },
    { codes: { code: string; name: string }[] }
>({ search: '' });
const found = ref<{ code: string; name: string }[]>([]);
// Поиск закончен для текущего запроса: можно сказать «ничего не найдено».
const searched = ref(false);
// Вариант, выбранный стрелками: Enter его выбирает.
const active = ref(-1);
let lookupRequest = 0;
let lookupTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(lookupTimer);
    searched.value = false;
    active.value = -1;
    lookupTimer = setTimeout(() => void find(value), 250);
});

async function find(value: string): Promise<void> {
    const request = ++lookupRequest;

    if (value.trim().length < 2) {
        found.value = [];

        return;
    }

    lookup.search = value.trim();

    try {
        const result = await lookup.get(Icd10Controller.index.url());

        if (request === lookupRequest) {
            found.value = result.codes;
            searched.value = true;
        }
    } catch {
        toast.error('Не удалось найти диагноз. Попробуйте ещё раз.');
    }
}

// Стрелки ходят по найденному, Enter выбирает, Escape закрывает список.
function onSearchKey(event: KeyboardEvent): void {
    if (found.value.length === 0) {
        return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const step = event.key === 'ArrowDown' ? 1 : -1;
        active.value =
            (active.value + step + found.value.length) % found.value.length;
        document
            .getElementById(`icd10-option-${active.value}`)
            ?.scrollIntoView({ block: 'nearest' });
    } else if (event.key === 'Enter' && active.value >= 0) {
        event.preventDefault();
        choose(found.value[active.value]);
    } else if (event.key === 'Escape') {
        found.value = [];
    }
}

function choose(code: { code: string; name: string }): void {
    chosen.value = code;
    diagnosisForm.icd10_code = code.code;
    diagnosisForm.clearErrors('icd10_code');
    found.value = [];
    search.value = '';
}

function addDiagnosis(): void {
    diagnosisForm.submit(
        ClinicalProfileController.storeDiagnosis(props.patient.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                chosen.value = null;
                diagnosisForm.reset('icd10_code');
                diagnosisForm.is_main = props.profile.diagnoses.every(
                    (diagnosis) => !diagnosis.is_main,
                );
            },
        },
    );
}

// Снять диагноз: дата снятия по умолчанию сегодня.
const resolving = ref<number | null>(null);
const resolveForm = useForm<{ resolved_on: string }>({
    resolved_on: props.today,
});

function resolve(diagnosis: ClinicalDiagnosis): void {
    resolveForm.submit(
        ClinicalProfileController.resolveDiagnosis({
            patient: props.patient.id,
            diagnosis: diagnosis.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                resolving.value = null;
                resolveForm.reset();
            },
        },
    );
}
</script>

<template>
    <Head :title="`Клинический профиль: ${patient.name}`" />

    <div class="@container flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-col gap-3">
            <Button variant="ghost" size="sm" class="-ml-3 self-start" as-child>
                <Link :href="show(patient.id)">
                    <ArrowLeft />
                    К карточке пациента
                </Link>
            </Button>
            <div class="flex flex-col gap-1">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Клинический профиль
                </h1>
                <p class="text-muted-foreground">Пациент: {{ patient.name }}</p>
            </div>
            <p class="max-w-3xl text-sm text-muted-foreground">
                По этим данным проверяется безопасность назначений. Пациент их
                не видит. Факт не перезаписывается: новое значение сохраняется
                отдельной записью, прежние остаются в истории.
            </p>
        </header>

        <div
            class="grid grid-cols-[minmax(0,1fr)] items-start gap-6 @4xl:grid-cols-[22rem_minmax(0,1fr)]"
        >
            <div class="flex flex-col gap-6">
                <section
                    class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
                    aria-labelledby="sex-title"
                    data-test="sex-section"
                >
                    <div>
                        <h2 id="sex-title" class="text-lg font-semibold">
                            Пол
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Нужен, чтобы проверялись беременность и лактация.
                        </p>
                    </div>
                    <form
                        class="flex flex-col gap-3"
                        novalidate
                        @submit.prevent="saveSex"
                    >
                        <div
                            class="flex flex-wrap gap-2"
                            role="radiogroup"
                            aria-labelledby="sex-title"
                        >
                            <label
                                v-for="option in [
                                    ...sexOptions,
                                    { value: '', label: 'Не указан' },
                                ]"
                                :key="option.value"
                                class="flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-input bg-card px-3 text-sm transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid pointer-coarse:min-h-11"
                            >
                                <input
                                    v-model="sexForm.sex"
                                    type="radio"
                                    name="sex"
                                    :value="option.value"
                                    class="size-4 shrink-0 outline-none"
                                />
                                {{ option.label }}
                            </label>
                        </div>
                        <InputError :message="sexForm.errors.sex" />
                        <Button
                            v-if="sexForm.sex !== (profile.sex ?? '')"
                            type="submit"
                            size="sm"
                            class="self-start"
                            :disabled="sexForm.processing"
                            data-test="save-sex"
                        >
                            Сохранить пол
                        </Button>
                    </form>
                </section>

                <section
                    id="diagnoses"
                    class="flex scroll-mt-20 flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
                    aria-labelledby="diagnoses-title"
                    data-test="diagnoses-section"
                >
                    <div>
                        <h2 id="diagnoses-title" class="text-lg font-semibold">
                            Диагнозы
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            По МКБ-10. Диагноз не удаляется, а снимается с
                            датой.
                        </p>
                    </div>

                    <p
                        v-if="profile.diagnoses.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Диагноз не указан: показания к назначению не
                        проверяются.
                    </p>

                    <ul v-else class="flex flex-col divide-y rounded-lg border">
                        <li
                            v-for="diagnosis in profile.diagnoses"
                            :key="diagnosis.id"
                            class="flex flex-col gap-2 p-3 text-sm"
                            data-test="diagnosis"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p>
                                        <span class="font-semibold">{{
                                            diagnosis.code
                                        }}</span>
                                        {{ diagnosis.name }}
                                        <Badge
                                            v-if="diagnosis.is_main"
                                            variant="secondary"
                                            class="ml-1 align-middle"
                                            >основной</Badge
                                        >
                                    </p>
                                    <p class="text-muted-foreground">
                                        С {{ diagnosis.established_on }},
                                        {{ diagnosis.created_by }}
                                    </p>
                                </div>
                                <Button
                                    v-if="resolving !== diagnosis.id"
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    class="-my-1 shrink-0"
                                    :aria-label="`Снять диагноз ${diagnosis.code}`"
                                    data-test="resolve-diagnosis"
                                    @click="resolving = diagnosis.id"
                                >
                                    Снять
                                </Button>
                            </div>

                            <form
                                v-if="resolving === diagnosis.id"
                                class="flex flex-wrap items-end gap-2 rounded-lg bg-muted/60 p-3"
                                @submit.prevent="resolve(diagnosis)"
                            >
                                <div class="grid gap-2">
                                    <Label :for="`resolved_on_${diagnosis.id}`"
                                        >Дата снятия</Label
                                    >
                                    <Input
                                        :id="`resolved_on_${diagnosis.id}`"
                                        v-model="resolveForm.resolved_on"
                                        type="date"
                                        :max="today"
                                        required
                                        :aria-invalid="
                                            resolveForm.errors.resolved_on
                                                ? true
                                                : undefined
                                        "
                                        :aria-describedby="`resolved_on_${diagnosis.id}-error`"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="destructive"
                                    :disabled="resolveForm.processing"
                                    data-test="confirm-resolve"
                                >
                                    Снять диагноз
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="resolving = null"
                                >
                                    Отмена
                                </Button>
                                <InputError
                                    :id="`resolved_on_${diagnosis.id}-error`"
                                    class="basis-full"
                                    :message="resolveForm.errors.resolved_on"
                                />
                            </form>
                        </li>
                    </ul>

                    <form
                        class="flex flex-col gap-3 border-t pt-3"
                        novalidate
                        data-test="add-diagnosis"
                        @submit.prevent="addDiagnosis"
                    >
                        <h3 class="font-medium">Добавить диагноз</h3>

                        <div class="grid gap-2">
                            <Label for="icd10_search"
                                >Код или название по МКБ-10</Label
                            >
                            <div class="relative">
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <Input
                                    id="icd10_search"
                                    v-model="search"
                                    type="search"
                                    autocomplete="off"
                                    class="pl-8"
                                    placeholder="Например: F41.1 или тревожное"
                                    role="combobox"
                                    aria-autocomplete="list"
                                    aria-controls="icd10-results"
                                    :aria-expanded="found.length > 0"
                                    :aria-activedescendant="
                                        active >= 0
                                            ? `icd10-option-${active}`
                                            : undefined
                                    "
                                    :aria-invalid="
                                        diagnosisForm.errors.icd10_code
                                            ? true
                                            : undefined
                                    "
                                    aria-describedby="icd10-status icd10_code-error"
                                    @keydown="onSearchKey"
                                />
                            </div>
                            <ul
                                v-show="found.length > 0"
                                id="icd10-results"
                                role="listbox"
                                class="flex max-h-64 flex-col overflow-y-auto rounded-lg border"
                                aria-label="Найденные диагнозы"
                                data-test="icd10-results"
                            >
                                <li
                                    v-for="(code, position) in found"
                                    :id="`icd10-option-${position}`"
                                    :key="code.code"
                                    role="option"
                                    :aria-selected="position === active"
                                    class="cursor-pointer px-3 py-2 text-sm hover:bg-accent pointer-coarse:py-3"
                                    :class="{
                                        'bg-accent': position === active,
                                    }"
                                    @mousedown.prevent
                                    @click="choose(code)"
                                >
                                    <span class="font-medium">{{
                                        code.code
                                    }}</span>
                                    {{ code.name }}
                                </li>
                            </ul>
                            <p
                                id="icd10-status"
                                class="text-sm text-muted-foreground"
                                aria-live="polite"
                            >
                                <template v-if="lookup.processing"
                                    >Ищем…</template
                                >
                                <template
                                    v-else-if="searched && found.length === 0"
                                    >Ничего не найдено. Проверьте код или
                                    напишите название иначе.</template
                                >
                                <span
                                    v-else-if="found.length > 0"
                                    class="sr-only"
                                    >{{
                                        `Найдено: ${found.length}. Выберите стрелками и нажмите Enter.`
                                    }}</span
                                >
                            </p>
                            <p
                                v-if="chosen"
                                class="rounded-lg bg-secondary p-2 text-sm text-secondary-foreground"
                                data-test="chosen-diagnosis"
                            >
                                Выбран:
                                <span class="font-semibold">{{
                                    chosen.code
                                }}</span>
                                {{ chosen.name }}
                            </p>
                            <InputError
                                id="icd10_code-error"
                                :message="diagnosisForm.errors.icd10_code"
                            />
                        </div>

                        <fieldset class="flex flex-col gap-2 text-sm">
                            <legend class="mb-2 font-medium">Вид</legend>
                            <div class="flex flex-wrap gap-2">
                                <label
                                    v-for="option in [
                                        { value: true, label: 'Основной' },
                                        {
                                            value: false,
                                            label: 'Сопутствующий',
                                        },
                                    ]"
                                    :key="option.label"
                                    class="flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-input bg-card px-3 transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid pointer-coarse:min-h-11"
                                >
                                    <input
                                        v-model="diagnosisForm.is_main"
                                        type="radio"
                                        name="is_main"
                                        :value="option.value"
                                        class="size-4 shrink-0 outline-none"
                                    />
                                    {{ option.label }}
                                </label>
                            </div>
                            <InputError
                                :message="diagnosisForm.errors.is_main"
                            />
                        </fieldset>

                        <div class="grid gap-2">
                            <Label for="established_on"
                                >Дата установления</Label
                            >
                            <Input
                                id="established_on"
                                v-model="diagnosisForm.established_on"
                                type="date"
                                class="max-w-48"
                                :max="today"
                                required
                                :aria-invalid="
                                    diagnosisForm.errors.established_on
                                        ? true
                                        : undefined
                                "
                                aria-describedby="established_on-error"
                            />
                            <InputError
                                id="established_on-error"
                                :message="diagnosisForm.errors.established_on"
                            />
                        </div>

                        <div
                            class="flex flex-wrap items-center gap-x-3 gap-y-1"
                        >
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="
                                    diagnosisForm.processing || chosen === null
                                "
                                aria-describedby="save-diagnosis-hint"
                                data-test="save-diagnosis"
                            >
                                Добавить диагноз
                            </Button>
                            <span
                                v-if="chosen === null"
                                id="save-diagnosis-hint"
                                class="text-sm text-muted-foreground"
                                >Сначала выберите диагноз из списка.</span
                            >
                        </div>
                    </form>

                    <details
                        v-if="profile.resolved_diagnoses.length > 0"
                        class="border-t pt-3 text-sm text-muted-foreground"
                    >
                        <summary
                            class="w-fit cursor-pointer rounded-sm hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                        >
                            Снятые диагнозы:
                            {{ profile.resolved_diagnoses.length }}
                        </summary>
                        <ul class="mt-2 flex flex-col gap-1">
                            <li
                                v-for="diagnosis in profile.resolved_diagnoses"
                                :key="diagnosis.id"
                            >
                                {{ diagnosis.code }} {{ diagnosis.name }} · с
                                {{ diagnosis.established_on }} по
                                {{ diagnosis.resolved_on }}, снял
                                {{ diagnosis.resolved_by }}
                            </li>
                        </ul>
                    </details>
                </section>
            </div>

            <section
                id="facts"
                class="flex scroll-mt-20 flex-col gap-3"
                aria-labelledby="facts-title"
                data-test="facts-section"
            >
                <div>
                    <h2 id="facts-title" class="text-lg font-semibold">
                        Факты
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Состояния и показатели из противопоказаний препаратов
                        справочника. Отметьте новые значения и сохраните их
                        вместе.
                    </p>
                </div>
                <p
                    v-if="hiddenFemaleOnly.length > 0"
                    class="rounded-lg bg-muted/60 p-3 text-sm text-muted-foreground"
                >
                    {{
                        `Только у женщин: ${hiddenFemaleOnly.join(', ')}. Укажите пол «Женский», чтобы внести.`
                    }}
                </p>

                <div class="flex flex-col rounded-xl border bg-card shadow-xs">
                    <div
                        class="hidden grid-cols-[minmax(0,15rem)_minmax(0,1fr)] gap-x-6 border-b px-4 py-2 text-sm text-muted-foreground @lg:grid"
                        aria-hidden="true"
                    >
                        <span>Сейчас</span>
                        <span>Новое значение</span>
                    </div>
                    <ul class="@container flex flex-col divide-y">
                        <ObservationForm
                            v-for="type in types"
                            :key="type.id"
                            v-model:draft="drafts[type.id]"
                            :type="type"
                            :today="today"
                            :comment-limit="limits.comment"
                            :errors="factErrors(type.id)"
                        />
                    </ul>
                </div>

                <!-- Кнопка сохранения закреплена внизу, пока есть несохранённые значения. -->
                <div
                    v-if="changed.length > 0"
                    class="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t bg-background/95 px-4 py-3 backdrop-blur-sm md:-mx-6 md:px-6"
                    data-test="facts-save-bar"
                >
                    <p class="min-w-0 flex-1 text-sm">
                        <span class="font-medium">Не сохранено: </span>
                        <template
                            v-for="(type, position) in changed"
                            :key="type.id"
                        >
                            <template v-if="position > 0">, </template>
                            <button
                                type="button"
                                class="rounded-sm text-left underline underline-offset-4 hover:text-primary"
                                @click="focusFact(type)"
                            >
                                {{ draftLabel(type) }}
                            </button>
                        </template>
                    </p>
                    <div class="ml-auto flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="resetFacts"
                        >
                            Сбросить
                        </Button>
                        <Button
                            type="button"
                            :disabled="factsForm.processing"
                            :aria-busy="factsForm.processing"
                            data-test="save-observations"
                            @click="saveFacts"
                        >
                            <Spinner v-if="factsForm.processing" />
                            Сохранить факты
                        </Button>
                    </div>
                </div>
                <InputError :message="factsForm.errors.observations" />
            </section>
        </div>
    </div>
</template>
