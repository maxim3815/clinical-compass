<script setup lang="ts">
import { Head, router, useForm, useRemember } from '@inertiajs/vue3';
import { CircleAlert, ExternalLink } from '@lucide/vue';
import { computed, nextTick, reactive, ref, useTemplateRef } from 'vue';
import AssessmentController from '@/actions/App/Http/Controllers/Patient/AssessmentController';
import LeaveConfirmDialog from '@/components/LeaveConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import { plural } from '@/lib/plural';
import { home } from '@/routes/patient';
import type { QuestionnaireScale } from '@/types';

const props = defineProps<{
    scales: QuestionnaireScale[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Сегодня',
                href: home(),
            },
            {
                title: 'Вопросы о самочувствии',
                href: AssessmentController.create(),
            },
        ],
    },
});

/*
 * Опросники идут по очереди, каждый на своём шаге: сначала GAD-7, потом PHQ-9. Переход
 * к следующей части — запись в истории браузера: «Назад» на телефоне со второй части
 * возвращает к первой, а не на «Сегодня». Шаг и ответы Inertia запоминает в записи истории
 * (useRemember, useForm с ключом) и восстанавливает при возврате.
 */
// Реактивный объект Inertia возвращает как есть, уже запомненный.
const position = useRemember(reactive({ step: 0 }), 'questionnaire-step') as {
    step: number;
};
const scale = computed(() => props.scales[position.step]);
const isLast = computed(() => position.step === props.scales.length - 1);
let pushedStep = position.step;

const form = useForm<{ answers: Record<string, (number | null)[]> }>(
    'questionnaire',
    {
        answers: Object.fromEntries(
            props.scales.map((scale) => [
                scale.code,
                scale.items.map(() => null),
            ]),
        ),
    },
);

// Вопросы без ответа подсвечиваются после попытки перейти дальше.
const checked = ref<Record<string, boolean>>({});

function unanswered(code: string): number[] {
    return form.answers[code]
        .map((score, index) => (score === null ? index : -1))
        .filter((index) => index >= 0);
}

const missing = computed(() => unanswered(scale.value.code));

function questionError(code: string, index: number): string | undefined {
    const serverError = (form.errors as Record<string, string | undefined>)[
        `answers.${code}.${index}`
    ];

    if (serverError) {
        return serverError;
    }

    return checked.value[code] && form.answers[code][index] === null
        ? 'Ответьте на этот вопрос.'
        : undefined;
}

// Ошибки сервера не про отдельный вопрос: опросник не пришёл целиком.
const formError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors.answers ??
        props.scales.map((item) => errors[`answers.${item.code}`]).find(Boolean)
    );
});

const content = useTemplateRef<HTMLElement>('content');
const heading = useTemplateRef<HTMLElement>('heading');

async function focusFirstUnanswered(): Promise<void> {
    await nextTick();
    content.value
        ?.querySelector<HTMLInputElement>(
            'fieldset[aria-invalid="true"] input[type="radio"]',
        )
        ?.focus();
}

async function showStep(index: number): Promise<void> {
    if (index > pushedStep) {
        window.history.pushState(null, '', window.location.href);
        pushedStep = index;
    }

    position.step = index;
    await nextTick();
    window.scrollTo({ top: 0 });
    heading.value?.focus();
}

function next(): void {
    checked.value[scale.value.code] = true;

    if (missing.value.length > 0) {
        void focusFirstUnanswered();

        return;
    }

    if (!isLast.value) {
        void showStep(position.step + 1);

        return;
    }

    form.post(AssessmentController.store.url(), {
        // Экран «Ответы переданы» заменяет вторую часть в истории, а запомненные ответы
        // стираются: «Назад» после отправки не открывает заполненную форму для повторной
        // отправки. При ошибке форма запомнится снова при следующем изменении.
        replace: true,
        onStart: () => {
            router.remember(undefined, 'questionnaire');
            router.remember(undefined, 'questionnaire-step');
        },
        onError: async (errors) => {
            // Ошибка может быть в опроснике прошлого шага: возвращаемся к нему.
            const failed = props.scales.findIndex((item) =>
                Object.keys(errors).some((key) =>
                    key.startsWith(`answers.${item.code}`),
                ),
            );

            if (failed >= 0 && failed !== position.step) {
                await showStep(failed);
            }

            void focusFirstUnanswered();
        },
    });
}

function questionsWord(count: number): string {
    return plural(count, ['вопрос', 'вопроса', 'вопросов']);
}

const stepLabel = computed(
    () =>
        `Часть ${position.step + 1} из ${props.scales.length} · ${scale.value.items.length} ${questionsWord(scale.value.items.length)}`,
);

// После попытки перейти дальше называем номера пропущенных вопросов.
const progress = computed(() => {
    const count = missing.value.length;

    if (count === 0) {
        return isLast.value
            ? 'Вы ответили на все вопросы.'
            : 'Вы ответили на все вопросы этой части.';
    }

    if (!checked.value[scale.value.code]) {
        return `Осталось ответить на ${count} ${questionsWord(count)}.`;
    }

    const numbers = missing.value.map((index) => index + 1);

    return count === 1
        ? `Нет ответа на вопрос ${numbers[0]}.`
        : `Нет ответа на вопросы ${numbers.slice(0, -1).join(', ')} и ${numbers.at(-1)}.`;
});

// Уход с начатых ответов переспрашивает: панели вкладок здесь нет, но ссылка назад в шапке есть.
const leaveGuard = useLeaveGuard(
    () =>
        !form.processing &&
        !form.wasSuccessful &&
        Object.values(form.answers).some((scores) =>
            scores.some((score) => score !== null),
        ),
);
</script>

<template>
    <Head title="Вопросы о самочувствии" />

    <div ref="content" class="flex flex-col gap-6 p-4">
        <header class="flex flex-col gap-2">
            <p
                class="text-muted-foreground"
                aria-hidden="true"
                data-test="questionnaire-step"
            >
                {{ stepLabel }}
            </p>
            <!-- После перехода фокус на заголовке: скринридер читает и номер части. -->
            <h1
                ref="heading"
                class="text-2xl font-semibold tracking-tight outline-none"
                tabindex="-1"
            >
                <span class="sr-only">{{
                    `${stepLabel.replace(' · ', ', ')}. `
                }}</span
                >{{ scale.title }}
            </h1>
            <p class="text-muted-foreground">Опросник {{ scale.name }}</p>
            <p class="rounded-2xl bg-secondary p-4 text-secondary-foreground">
                {{ scale.instruction }}
            </p>
        </header>

        <form
            class="flex flex-col gap-6"
            novalidate
            :data-test="`questionnaire-${scale.code}`"
            @submit.prevent="next"
        >
            <ol class="flex flex-col gap-4">
                <li
                    v-for="(item, index) in scale.items"
                    :key="`${scale.code}-${index}`"
                >
                    <fieldset
                        class="flex flex-col gap-2 rounded-2xl border bg-card p-4 shadow-xs"
                        :class="
                            questionError(scale.code, index)
                                ? 'border-danger'
                                : ''
                        "
                        role="radiogroup"
                        aria-required="true"
                        :aria-invalid="
                            questionError(scale.code, index) ? true : undefined
                        "
                        :aria-describedby="
                            questionError(scale.code, index)
                                ? `${scale.code}-${index}-error`
                                : undefined
                        "
                        data-test="question"
                    >
                        <legend class="float-left w-full font-medium">
                            <span class="text-muted-foreground"
                                >{{ index + 1 }}.</span
                            >
                            {{ item }}
                        </legend>
                        <!-- Ошибка сразу под вопросом: на телефоне варианты могут уводить её за экран. -->
                        <p
                            v-if="questionError(scale.code, index)"
                            :id="`${scale.code}-${index}-error`"
                            class="flex items-start gap-2 text-danger"
                            data-slot="input-error"
                        >
                            <CircleAlert class="mt-0.5 size-5 shrink-0" />
                            {{ questionError(scale.code, index) }}
                        </p>
                        <div class="mt-1 flex flex-col gap-2">
                            <label
                                v-for="option in scale.options"
                                :key="option.score"
                                class="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border border-input bg-card px-3 py-2 transition-colors has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid"
                            >
                                <input
                                    v-model="form.answers[scale.code][index]"
                                    type="radio"
                                    :name="`${scale.code}-${index}`"
                                    :value="option.score"
                                    class="size-5 shrink-0 outline-none"
                                />
                                {{ option.label }}
                            </label>
                        </div>
                    </fieldset>
                </li>
            </ol>

            <div class="flex flex-col gap-3">
                <p
                    v-if="formError"
                    class="flex items-start gap-2 text-danger"
                    role="alert"
                >
                    <CircleAlert class="mt-0.5 size-5 shrink-0" />
                    {{ formError }}
                </p>
                <p
                    :class="
                        checked[scale.code] && missing.length > 0
                            ? 'text-danger'
                            : 'text-muted-foreground'
                    "
                    aria-live="polite"
                    data-test="questions-left"
                >
                    {{ progress }}
                </p>
                <Button
                    type="submit"
                    size="xl"
                    :disabled="form.processing"
                    data-test="questionnaire-next"
                >
                    <Spinner v-if="form.processing" class="size-5" />
                    {{ isLast ? 'Отправить врачу' : 'Дальше' }}
                </Button>
                <Button
                    v-if="position.step > 0"
                    type="button"
                    variant="outline"
                    size="lg"
                    @click="showStep(position.step - 1)"
                >
                    Назад к части {{ position.step }}
                </Button>
            </div>
        </form>

        <footer class="flex flex-col gap-1 text-muted-foreground">
            <p>Источник: {{ scale.source.validation }}</p>
            <p>{{ scale.source.license }}</p>
            <a
                :href="scale.source.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex min-h-11 items-center gap-1 self-start text-primary underline underline-offset-4"
            >
                Открыть документ<span class="sr-only">{{
                    ` ${scale.name}`
                }}</span>
                <ExternalLink class="size-4" />
            </a>
        </footer>

        <LeaveConfirmDialog
            :pending="leaveGuard.pending.value"
            lost="Ваши ответы не сохранятся."
            @leave="leaveGuard.leave"
            @stay="leaveGuard.stay"
        />
    </div>
</template>
