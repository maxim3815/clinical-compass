<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { BellRing, ChevronDown, CircleAlert, Phone, Send } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import LeaveConfirmDialog from '@/components/LeaveConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import { medications } from '@/routes/patient';
import {
    create,
    index as sideEffects,
    store,
} from '@/routes/patient/side-effects';
import type { SelectOption, SideEffectGroup } from '@/types';

/**
 * Форма побочного эффекта: что беспокоит (можно несколько), насколько сильно и когда началось.
 * Список — из листков препаратов, которые пациент сейчас принимает; препарат он не выбирает.
 * Подтверждения нет, поэтому что произойдёт — над кнопкой. Симптомы «нужна помощь сейчас» первыми,
 * со звонком в 112; если отмечены только они, сила и начало необязательны: пациенту не до вопросов [медик].
 */
const props = defineProps<{
    groups: SideEffectGroup[];
    severities: SelectOption[];
    // Сегодня и самая ранняя дата начала по часовому поясу пациента, ГГГГ-ММ-ДД.
    today: string;
    minDate: string;
    maxLength: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Препараты',
                href: medications(),
            },
            {
                title: 'Побочные эффекты',
                href: sideEffects(),
            },
            {
                title: 'Сообщить',
                href: create(),
            },
        ],
    },
});

const starts = [
    { value: 'today', label: 'Сегодня' },
    { value: 'yesterday', label: 'Вчера' },
    { value: 'earlier', label: 'Раньше' },
];

const form = useForm<{
    symptoms: number[];
    other: string;
    severity: string | null;
    started: string | null;
    started_on: string;
}>({
    symptoms: [],
    other: '',
    severity: null,
    started: null,
    started_on: '',
});

function idsOf(level: string): Set<number> {
    return new Set(
        props.groups
            .filter((group) => group.level === level)
            .flatMap((group) => group.symptoms.map((symptom) => symptom.id)),
    );
}

const urgentIds = computed(() => idsOf('urgent'));
const emergencyIds = computed(() => idsOf('emergency'));
const emergencyChosen = computed(() =>
    form.symptoms.some((id) => emergencyIds.value.has(id)),
);
const urgentChosen = computed(() =>
    form.symptoms.some((id) => urgentIds.value.has(id)),
);
// Сила и начало необязательны, только если отмечено одно опасное: у остального они нужны врачу.
const onlyEmergency = computed(
    () =>
        form.symptoms.length > 0 &&
        form.symptoms.every((id) => emergencyIds.value.has(id)) &&
        form.other.trim() === '',
);
const hasEmergencyGroup = computed(() =>
    props.groups.some((group) => group.level === 'emergency'),
);

// Редкие симптомы свёрнуты: так до силы и кнопки ближе. Раскрыты, если в них уже что-то выбрано.
const showUncommon = ref(false);
const uncommonChosen = computed(() =>
    form.symptoms.some((id) => idsOf('uncommon').has(id)),
);

// После раскрытия фокус — на первом симптоме: кнопки «Показать ещё» больше нет.
async function expandUncommon(): Promise<void> {
    showUncommon.value = true;
    await nextTick();
    document
        .querySelector<HTMLElement>('[data-test="symptoms-uncommon"] input')
        ?.focus();
}

function isCollapsed(group: SideEffectGroup): boolean {
    return (
        group.level === 'uncommon' &&
        !showUncommon.value &&
        !uncommonChosen.value
    );
}

// Ошибки относятся к прежнему выбору: после правки поля убираем.
watch(
    () => [form.symptoms.length, form.other],
    () => form.clearErrors('symptoms', 'other'),
);
watch(
    () => form.severity,
    () => form.clearErrors('severity'),
);
watch(
    () => [form.started, form.started_on],
    () => form.clearErrors('started', 'started_on'),
);

const formElement = useTemplateRef<HTMLFormElement>('formElement');
const failure = useTemplateRef<HTMLElement>('failure');
// Отчёт не ушёл: нет сети или сервер не ответил. Ошибки полей показываются у полей.
const failed = ref(false);

function submit(): void {
    // Пока отчёт уходит, второе нажатие ничего не делает.
    if (form.processing) {
        return;
    }

    let answered = false;

    form.transform((data) => ({
        ...data,
        started_on: data.started === 'earlier' ? data.started_on : '',
    })).submit(store(), {
        // «Назад» из окна побочных эффектов — туда, откуда пришёл пациент, а не в заполненную форму.
        replace: true,
        onStart: () => (failed.value = false),
        onSuccess: () => (answered = true),
        onError: async () => {
            answered = true;
            await nextTick();
            formElement.value
                ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                ?.focus();
        },
        onFinish: async () => {
            if (answered) {
                return;
            }

            failed.value = true;
            await nextTick();
            failure.value?.focus();
        },
    });
}

const leaveGuard = useLeaveGuard(() => form.isDirty && !form.processing);

const rowClass =
    'flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-input bg-card px-3 py-2.5 text-base transition-colors has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid';

// Срочные группы видны издалека: рамка и значок у заголовка.
function groupClass(group: SideEffectGroup): string {
    if (group.level === 'emergency') {
        return 'rounded-2xl border-2 border-danger-border bg-danger-subtle p-3';
    }

    return group.level === 'urgent'
        ? 'rounded-2xl border border-primary/40 bg-secondary p-3'
        : '';
}
</script>

<template>
    <Head title="Сообщить о побочном эффекте" />

    <form
        ref="formElement"
        class="flex flex-col gap-8 p-4"
        novalidate
        data-test="side-effect-form"
        @submit.prevent="submit"
    >
        <header class="flex flex-col gap-3">
            <h1 class="text-2xl font-semibold tracking-tight">
                Что вас беспокоит?
            </h1>
            <!-- Если в листках препаратов пациента нет опасных симптомов, общая памятка про 112. -->
            <div
                v-if="!hasEmergencyGroup"
                class="flex flex-col gap-2 rounded-2xl bg-secondary p-4 text-secondary-foreground"
            >
                <p>
                    Если трудно дышать, отекло лицо или губы, начались судороги,
                    сразу звоните 112.
                </p>
                <Button
                    variant="outline"
                    size="lg"
                    class="self-start rounded-full bg-card"
                    as-child
                >
                    <a href="tel:112">
                        <Phone />
                        Позвонить 112
                    </a>
                </Button>
            </div>
        </header>

        <fieldset class="flex flex-col gap-5">
            <legend class="mb-3 text-xl font-semibold">
                {{
                    groups.length > 0
                        ? 'Отметьте всё, что подходит'
                        : 'Опишите своими словами'
                }}
            </legend>
            <InputError id="symptoms-error" :message="form.errors.symptoms" />

            <div
                v-for="(group, index) in groups"
                :key="group.level"
                class="flex flex-col gap-2"
                :class="groupClass(group)"
                :data-test="`symptoms-${group.level}`"
            >
                <h2
                    :id="`symptom-group-${index}`"
                    class="flex items-start gap-2 font-medium"
                    :class="{
                        'text-muted-foreground':
                            group.level === 'common' ||
                            group.level === 'uncommon',
                        'text-danger': group.level === 'emergency',
                    }"
                >
                    <BellRing
                        v-if="group.level === 'urgent'"
                        class="mt-0.5 size-5 shrink-0 text-primary"
                    />
                    <CircleAlert
                        v-if="group.level === 'emergency'"
                        class="mt-0.5 size-5 shrink-0"
                    />
                    {{ group.label }}
                </h2>
                <!-- Опасные симптомы первыми: звонок — ещё до отметки, после неё — главной кнопкой. -->
                <Button
                    v-if="group.level === 'emergency'"
                    :variant="emergencyChosen ? 'default' : 'outline'"
                    :size="emergencyChosen ? 'xl' : 'lg'"
                    :class="
                        emergencyChosen ? '' : 'self-start rounded-full bg-card'
                    "
                    as-child
                >
                    <a href="tel:112" data-test="side-effect-form-call-112">
                        <Phone />
                        Позвонить 112
                    </a>
                </Button>
                <Button
                    v-if="isCollapsed(group)"
                    type="button"
                    variant="outline"
                    size="lg"
                    class="self-start rounded-full"
                    data-test="show-uncommon"
                    @click="expandUncommon"
                >
                    <ChevronDown />
                    {{ `Показать ещё ${group.symptoms.length}` }}
                </Button>
                <div
                    v-else
                    class="flex flex-col gap-2"
                    role="group"
                    :aria-labelledby="`symptom-group-${index}`"
                >
                    <label
                        v-for="symptom in group.symptoms"
                        :key="symptom.id"
                        :class="rowClass"
                        :data-test="`symptom-${symptom.code}`"
                    >
                        <input
                            v-model="form.symptoms"
                            type="checkbox"
                            name="symptoms[]"
                            :value="symptom.id"
                            class="size-5 shrink-0 accent-primary outline-none"
                            :aria-invalid="
                                form.errors.symptoms ? true : undefined
                            "
                            aria-describedby="symptoms-error"
                        />
                        {{ symptom.name }}
                    </label>
                </div>
                <!-- Нужна помощь сейчас: что делать по порядку, рядом с отметкой. -->
                <p
                    v-if="group.level === 'emergency' && emergencyChosen"
                    class="font-semibold"
                    data-test="side-effect-emergency-hint"
                >
                    Сначала позвоните 112, потом нажмите «Отправить врачу»
                    внизу.
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="other" class="text-base">
                    {{
                        groups.length > 0
                            ? 'Другое, своими словами'
                            : 'Что беспокоит'
                    }}
                </Label>
                <Input
                    id="other"
                    v-model="form.other"
                    name="other"
                    :maxlength="maxLength"
                    autocomplete="off"
                    :aria-invalid="
                        form.errors.other || form.errors.symptoms
                            ? true
                            : undefined
                    "
                    aria-describedby="symptoms-error other-error"
                />
                <InputError id="other-error" :message="form.errors.other" />
            </div>
        </fieldset>

        <fieldset class="flex flex-col gap-2">
            <legend class="mb-3 text-xl font-semibold">
                {{
                    onlyEmergency
                        ? 'Насколько сильно? (можно не отвечать)'
                        : 'Насколько сильно?'
                }}
            </legend>
            <InputError id="severity-error" :message="form.errors.severity" />
            <label
                v-for="severity in severities"
                :key="severity.value"
                :class="rowClass"
                :data-test="`severity-${severity.value}`"
            >
                <input
                    v-model="form.severity"
                    type="radio"
                    name="severity"
                    :value="severity.value"
                    class="size-5 shrink-0 accent-primary outline-none"
                    :aria-invalid="form.errors.severity ? true : undefined"
                    aria-describedby="severity-error"
                />
                {{ severity.label }}
            </label>
        </fieldset>

        <fieldset class="flex flex-col gap-2">
            <legend class="mb-3 text-xl font-semibold">
                {{
                    onlyEmergency
                        ? 'Когда началось? (можно не отвечать)'
                        : 'Когда началось?'
                }}
            </legend>
            <InputError id="started-error" :message="form.errors.started" />
            <label
                v-for="start in starts"
                :key="start.value"
                :class="rowClass"
                :data-test="`started-${start.value}`"
            >
                <input
                    v-model="form.started"
                    type="radio"
                    name="started"
                    :value="start.value"
                    class="size-5 shrink-0 accent-primary outline-none"
                    :aria-invalid="form.errors.started ? true : undefined"
                    aria-describedby="started-error"
                />
                {{ start.label }}
            </label>
            <div v-if="form.started === 'earlier'" class="mt-2 grid gap-2">
                <Label for="started_on" class="text-base">Дата начала</Label>
                <Input
                    id="started_on"
                    v-model="form.started_on"
                    name="started_on"
                    type="date"
                    :min="minDate"
                    :max="today"
                    :aria-invalid="form.errors.started_on ? true : undefined"
                    aria-describedby="started_on-error"
                />
                <InputError
                    id="started_on-error"
                    :message="form.errors.started_on"
                />
            </div>
        </fieldset>

        <div class="flex flex-col gap-3">
            <!-- Не ушло: заметно и не исчезает само, звонок в 112 работает и без интернета. -->
            <div
                v-if="failed"
                ref="failure"
                role="alert"
                tabindex="-1"
                class="flex flex-col gap-3 rounded-xl border border-danger-border bg-danger-subtle p-3 outline-none"
                data-test="side-effect-failed"
            >
                <div class="flex items-start gap-3">
                    <CircleAlert class="mt-0.5 size-6 shrink-0 text-danger" />
                    <div class="flex flex-col gap-1">
                        <p class="font-semibold">Врачу не отправлено</p>
                        <p>
                            Проверьте интернет и нажмите ещё раз. Если есть
                            угроза жизни, звоните 112: звонок работает и без
                            интернета.
                        </p>
                    </div>
                </div>
                <Button
                    v-if="urgentChosen || emergencyChosen"
                    variant="outline"
                    size="lg"
                    class="rounded-full bg-card"
                    as-child
                >
                    <a href="tel:112">
                        <Phone />
                        Позвонить 112
                    </a>
                </Button>
            </div>

            <!-- Подтверждения нет, поэтому что произойдёт — над кнопкой. -->
            <div aria-live="polite">
                <p
                    v-if="urgentChosen || emergencyChosen"
                    id="side-effect-urgent-hint"
                    class="flex items-start gap-2 font-medium"
                    data-test="side-effect-urgent-hint"
                >
                    <BellRing class="mt-0.5 size-5 shrink-0 text-primary" />
                    {{
                        emergencyChosen
                            ? 'Сначала позвоните 112. Врач получит срочное уведомление.'
                            : 'Врач получит срочное уведомление.'
                    }}
                </p>
            </div>
            <Button
                type="submit"
                size="xl"
                :aria-disabled="form.processing || undefined"
                :aria-describedby="
                    urgentChosen || emergencyChosen
                        ? 'side-effect-urgent-hint'
                        : undefined
                "
                data-test="send-side-effect"
            >
                <Spinner v-if="form.processing" class="size-5" />
                <Send v-else />
                {{ form.processing ? 'Отправляем врачу…' : 'Отправить врачу' }}
            </Button>
            <Button variant="outline" size="lg" class="rounded-full" as-child>
                <Link :href="sideEffects()">Отмена</Link>
            </Button>
        </div>

        <LeaveConfirmDialog
            :pending="leaveGuard.pending.value"
            @leave="leaveGuard.leave"
            @stay="leaveGuard.stay"
        />
    </form>
</template>
