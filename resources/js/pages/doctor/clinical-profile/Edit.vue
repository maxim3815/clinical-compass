<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { ArrowLeft, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ClinicalProfileController from '@/actions/App/Http/Controllers/Doctor/ClinicalProfileController';
import Icd10Controller from '@/actions/App/Http/Controllers/Doctor/Icd10Controller';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ObservationForm from '@/components/ObservationForm.vue';
import type { ObservationTypeOption } from '@/components/ObservationForm.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
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
let lookupRequest = 0;
let lookupTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(lookupTimer);
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
        }
    } catch {
        toast.error('Не удалось найти диагноз. Попробуйте ещё раз.');
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

    <div class="flex max-w-3xl flex-col gap-8 p-4">
        <div class="flex flex-col gap-3">
            <Heading title="Клинический профиль" :description="patient.name" />
            <p class="text-sm text-muted-foreground">
                По этим данным проверяется безопасность назначений. Пациент их
                не видит. Факт не перезаписывается: новое значение сохраняется
                отдельной записью, прежние остаются в истории.
            </p>
            <Button variant="outline" size="sm" class="self-start" as-child>
                <Link :href="show(patient.id)">
                    <ArrowLeft />
                    К карточке пациента
                </Link>
            </Button>
        </div>

        <section class="flex flex-col gap-3" data-test="sex-section">
            <Heading
                variant="small"
                title="Пол"
                description="Нужен, чтобы проверялись беременность и лактация."
            />
            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="saveSex"
            >
                <div class="grid gap-2">
                    <Label for="sex">Пол</Label>
                    <NativeSelect id="sex" v-model="sexForm.sex" name="sex">
                        <option value="">Не указан</option>
                        <option
                            v-for="option in sexOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </NativeSelect>
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="
                        sexForm.processing ||
                        sexForm.sex === (profile.sex ?? '')
                    "
                    data-test="save-sex"
                >
                    Сохранить
                </Button>
            </form>
            <InputError :message="sexForm.errors.sex" />
        </section>

        <section class="flex flex-col gap-3" data-test="diagnoses-section">
            <Heading
                variant="small"
                title="Диагнозы"
                description="По МКБ-10. Диагноз не удаляется, а снимается с датой."
            />

            <p
                v-if="profile.diagnoses.length === 0"
                class="text-sm text-muted-foreground"
            >
                Диагноз не указан: показания к назначению не проверяются.
            </p>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="diagnosis in profile.diagnoses"
                    :key="diagnosis.id"
                    class="flex flex-col gap-2 rounded-lg border p-3 text-sm"
                    data-test="diagnosis"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ diagnosis.code }}</span>
                        <span>{{ diagnosis.name }}</span>
                        <Badge v-if="diagnosis.is_main" variant="secondary"
                            >основной</Badge
                        >
                    </div>
                    <p class="text-muted-foreground">
                        С {{ diagnosis.established_on }},
                        {{ diagnosis.created_by }}
                    </p>

                    <form
                        v-if="resolving === diagnosis.id"
                        class="flex flex-wrap items-end gap-2"
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
                            class="basis-full"
                            :message="resolveForm.errors.resolved_on"
                        />
                    </form>
                    <Button
                        v-else
                        type="button"
                        size="sm"
                        variant="outline"
                        class="self-start"
                        data-test="resolve-diagnosis"
                        @click="resolving = diagnosis.id"
                    >
                        Снять
                    </Button>
                </li>
            </ul>

            <form
                class="flex flex-col gap-3 rounded-lg border border-dashed p-4"
                data-test="add-diagnosis"
                @submit.prevent="addDiagnosis"
            >
                <h3 class="text-sm font-medium">Добавить диагноз</h3>

                <div class="grid gap-2">
                    <Label for="icd10_search">Код или название по МКБ-10</Label>
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                        />
                        <Input
                            id="icd10_search"
                            v-model="search"
                            type="search"
                            autocomplete="off"
                            class="pl-8"
                            placeholder="Например: F41.1 или тревожное"
                        />
                    </div>
                    <ul
                        v-if="found.length > 0"
                        class="flex max-h-64 flex-col overflow-y-auto rounded-md border"
                        data-test="icd10-results"
                    >
                        <li v-for="code in found" :key="code.code">
                            <button
                                type="button"
                                class="w-full px-3 py-2 text-left text-sm hover:bg-accent"
                                @click="choose(code)"
                            >
                                <span class="font-medium">{{ code.code }}</span>
                                {{ code.name }}
                            </button>
                        </li>
                    </ul>
                    <p
                        v-if="chosen"
                        class="text-sm"
                        data-test="chosen-diagnosis"
                    >
                        Выбран:
                        <span class="font-medium">{{ chosen.code }}</span>
                        {{ chosen.name }}
                    </p>
                    <InputError :message="diagnosisForm.errors.icd10_code" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <fieldset class="flex flex-col gap-2 text-sm">
                        <legend class="mb-2 font-medium">Вид</legend>
                        <label class="flex items-center gap-2">
                            <input
                                v-model="diagnosisForm.is_main"
                                type="radio"
                                name="is_main"
                                :value="true"
                            />
                            Основной
                        </label>
                        <label class="flex items-center gap-2">
                            <input
                                v-model="diagnosisForm.is_main"
                                type="radio"
                                name="is_main"
                                :value="false"
                            />
                            Сопутствующий
                        </label>
                        <InputError :message="diagnosisForm.errors.is_main" />
                    </fieldset>
                    <div class="grid content-start gap-2">
                        <Label for="established_on">Дата установления</Label>
                        <Input
                            id="established_on"
                            v-model="diagnosisForm.established_on"
                            type="date"
                            :max="today"
                            required
                        />
                        <InputError
                            :message="diagnosisForm.errors.established_on"
                        />
                    </div>
                </div>

                <Button
                    type="submit"
                    size="sm"
                    class="self-start"
                    :disabled="diagnosisForm.processing || chosen === null"
                    data-test="save-diagnosis"
                >
                    Добавить диагноз
                </Button>
            </form>

            <details
                v-if="profile.resolved_diagnoses.length > 0"
                class="text-sm text-muted-foreground"
            >
                <summary class="cursor-pointer">Снятые диагнозы</summary>
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

        <section class="flex flex-col gap-3" data-test="facts-section">
            <Heading
                variant="small"
                title="Факты"
                description="Состояния и показатели из противопоказаний препаратов справочника."
            />
            <p
                v-if="hiddenFemaleOnly.length > 0"
                class="text-sm text-muted-foreground"
            >
                {{
                    `Только у женщин: ${hiddenFemaleOnly.join(', ')}. Укажите пол «Женский», чтобы внести.`
                }}
            </p>

            <ObservationForm
                v-for="type in types"
                :key="type.id"
                :patient-id="patient.id"
                :type="type"
                :today="today"
                :comment-limit="limits.comment"
            />
        </section>
    </div>
</template>
