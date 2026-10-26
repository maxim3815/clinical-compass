<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CircleCheck, ClipboardPen, Siren } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import QueueAnswerController from '@/actions/App/Http/Controllers/Doctor/QueueAnswerController';
import QueueAnswerScaleController from '@/actions/App/Http/Controllers/Doctor/QueueAnswerScaleController';
import AppointmentInvitation from '@/components/AppointmentInvitation.vue';
import AssessmentAnswers from '@/components/AssessmentAnswers.vue';
import DeclineReferralDialog from '@/components/DeclineReferralDialog.vue';
import ReferralBasis from '@/components/ReferralBasis.vue';
import ReferralCategoryBadge from '@/components/ReferralCategoryBadge.vue';
import { Button } from '@/components/ui/button';
import { useRealtime } from '@/composables/useRealtime';
import { alertTitle } from '@/lib/alerts';
import { index, show } from '@/routes/doctor/patients';
import type {
    FilledAssessment,
    RatableScale,
    ReferralCategory,
    ReferralSuggestion,
} from '@/types';

/**
 * Ответы из очереди у двери, открытые по коду: опросники, HADS и признаки до регистрации,
 * подсказка направления. Направляют из карточки: сначала «Пригласить на приёме», и после
 * регистрации ответы с HADS переходят пациенту. Без направления — «Не направлять», как в карточке.
 */
type Props = {
    answer: {
        id: number;
        code: string;
        submitted_at: string;
        expires_at: string;
        alert: { rule: string; trigger: string } | null;
        // current — решение принято при нынешней подсказке; HADS могла её поднять.
        declined: {
            at: string;
            comment: string | null;
            suggestion: ReferralCategory | null;
            current: boolean;
        } | null;
        // Признаки, отмеченные вместе с HADS.
        facts: { name: string; value: boolean }[];
    };
    fillings: (FilledAssessment & { submitted_at: string | null })[];
    suggestion: ReferralSuggestion;
    doctorScales: RatableScale[];
    commentLimit: number;
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: Props) => ({
        breadcrumbs: [
            { title: 'Пациенты', href: index() },
            {
                title: `Код ${props.answer.code}`,
                href: QueueAnswerController.show(props.answer.id),
            },
        ],
    }),
});

const page = usePage();
const declining = ref(false);
// Пациент зарегистрировался по PIN с этой страницы: ответы уже в его карточке.
const registered = ref<{ id: number; name: string } | null>(null);
// Открыто окно PIN: страница не обновляется под ним.
const inviting = ref(false);

// Подсказка «обязательно» — выше категории нет: HADS её уже не изменит.
const required = computed(() => props.suggestion.category === 'required');

// «Не направлять» действует, пока подсказка та же; выросла — терапевт решает снова.
const declinedCurrent = computed(() => props.answer.declined?.current ?? false);

const missingDoctorScales = computed(() =>
    props.suggestion.missing.filter((scale) => scale.rater === 'doctor'),
);

const missingPatientScales = computed(() =>
    props.suggestion.missing.filter((scale) => scale.rater === 'patient'),
);

const declinedText = computed(() => {
    if (props.answer.declined === null) {
        return null;
    }

    const comment = props.answer.declined.comment
        ? `: «${props.answer.declined.comment}»`
        : '';

    if (!props.answer.declined.current) {
        const was =
            props.answer.declined.suggestion === 'required'
                ? '«обязательно»'
                : props.answer.declined.suggestion === 'desirable'
                  ? '«желательно»'
                  : 'без подсказки';

        return `${props.answer.declined.at} вы решили не направлять при подсказке ${was}${comment}. С тех пор подсказка изменилась: решите снова.`;
    }

    return `${props.answer.declined.at} вы решили не направлять${comment}. Если передумаете, пациента ещё можно зарегистрировать.`;
});

const factsText = computed(() =>
    props.answer.facts
        .map(
            (fact) =>
                `${fact.name.charAt(0).toLowerCase()}${fact.name.slice(1)} — ${fact.value ? 'есть' : 'нет'}`,
        )
        .join('; '),
);

// HADS уже заполнена: кнопка «Заполнить заново» у ответов.
const ratedScales = computed(() =>
    props.doctorScales.filter((scale) =>
        props.fillings.some((filling) => filling.scale === scale.code),
    ),
);

function rateHref(scale: string) {
    return QueueAnswerScaleController.create({
        queueAnswer: props.answer.id,
        scale,
    });
}

// Кнопка «Не направлять» после решения исчезает: фокус — на заголовок блока.
let decided = false;

function onDecided(): void {
    decided = true;
}

function returnFocus(event: Event): void {
    if (decided) {
        event.preventDefault();
        decided = false;
        void nextTick(() => document.getElementById('referral-title')?.focus());
    }
}

// Кнопки «Пригласить на приёме» больше нет: фокус — на сообщение о регистрации. После того,
// как окно вернёт фокус на исчезнувшую кнопку.
// Ответы изменились в другом месте: пациент зарегистрировался с этого телефона по общему PIN,
// HADS или решение в другой вкладке. Привязанные ответы сервер перенаправит в карточку.
useRealtime(
    `App.Models.User.${page.props.auth.user.id}`,
    'queue-answers.changed',
    () => {
        // Под открытым окном страница не меняется: набранный комментарий не пропадёт.
        if (!inviting.value && !declining.value && registered.value === null) {
            router.reload({ only: ['answer', 'fillings', 'suggestion'] });
        }
    },
);

function onRegistered(patient: { id: number; name: string }): void {
    registered.value = patient;
    void nextTick(() =>
        setTimeout(() => document.getElementById('queue-registered')?.focus()),
    );
}
</script>

<template>
    <Head :title="`Код ${answer.code}`" />

    <div class="@container flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-col gap-1">
            <p class="text-sm text-muted-foreground">Ответы из очереди</p>
            <h1 class="text-2xl font-semibold tracking-tight tabular-nums">
                {{ `Код ${answer.code}` }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{
                    registered
                        ? `Ответы в ${answer.submitted_at}, теперь в карточке пациента.`
                        : `Ответы в ${answer.submitted_at}, без регистрации. Если не зарегистрировать пациента, ответы удалятся ${answer.expires_at}.`
                }}
            </p>
        </header>

        <div
            v-if="registered"
            id="queue-registered"
            tabindex="-1"
            class="flex flex-col gap-3 rounded-xl border border-success-border bg-success-subtle p-4 outline-none"
            role="status"
            data-test="queue-registered"
        >
            <p class="flex gap-2 font-medium">
                <CircleCheck class="mt-0.5 size-5 shrink-0 text-success" />
                {{
                    `Регистрация завершена: ${registered.name}. Ответы из очереди и HADS — в карточке пациента, направить можно оттуда.`
                }}
            </p>
            <Button as-child class="self-start">
                <Link :href="show(registered.id)" data-test="queue-open-patient"
                    >Открыть карточку и направить</Link
                >
            </Button>
        </div>

        <section
            v-if="answer.alert"
            class="flex flex-col gap-2 rounded-xl border-2 border-danger bg-danger-subtle p-4"
            aria-labelledby="queue-alert-title"
            data-test="queue-alert"
        >
            <h2
                id="queue-alert-title"
                class="flex items-start gap-2 font-semibold text-danger"
            >
                <Siren class="mt-0.5 size-5 shrink-0" />
                {{ alertTitle(answer.alert.rule, answer.alert.trigger) }}
            </h2>
            <p class="text-sm">
                {{
                    registered
                        ? 'Алерт — в карточке пациента, уже принятый. Решение по нему — там.'
                        : 'Пациенту показаны 112, кризисная линия и просьба постучать и назвать код вам, можно без очереди. После регистрации алерт появится в карточке уже принятым, решение по нему — там.'
                }}
            </p>
        </section>

        <section
            v-if="!registered"
            class="flex flex-col gap-3"
            aria-labelledby="referral-title"
            data-test="referral-section"
        >
            <h2
                id="referral-title"
                tabindex="-1"
                class="text-lg font-semibold outline-none"
            >
                Направление к психиатру
            </h2>

            <div
                class="flex flex-col gap-4 rounded-xl border bg-card p-4 shadow-xs"
                data-test="referral-suggestion"
            >
                <div v-if="suggestion.category" class="flex flex-col gap-2">
                    <p class="text-sm text-muted-foreground">
                        Подсказка системы
                    </p>
                    <ReferralCategoryBadge
                        :category="suggestion.category"
                        prefix=""
                        large
                        class="self-start"
                    />
                </div>
                <p
                    v-else
                    class="flex items-start gap-2"
                    data-test="referral-not-suggested"
                >
                    <CircleCheck
                        class="mt-0.5 size-5 shrink-0 text-success"
                        aria-hidden="true"
                    />
                    По шкалам и признакам направление к психиатру не
                    подсказывается.
                </p>

                <ReferralBasis
                    v-if="suggestion.matches.length > 0"
                    :matches="suggestion.matches"
                />

                <div
                    v-if="suggestion.missing.length > 0"
                    class="flex flex-col gap-2 rounded-lg bg-muted p-3 text-sm"
                    data-test="referral-missing"
                >
                    <p v-if="missingDoctorScales.length > 0">
                        {{
                            required
                                ? `${missingDoctorScales.map((scale) => scale.name).join(', ')} не заполнена. Подсказку она уже не изменит: выше «обязательно» категории нет. Заполнить можно для психиатра.`
                                : `${missingDoctorScales.map((scale) => scale.name).join(', ')} не заполнена: правила по ней не проверены, подсказка может стать выше.`
                        }}
                    </p>
                    <p v-if="missingPatientScales.length > 0">
                        {{
                            `Нет ответов: ${missingPatientScales.map((scale) => scale.name).join(', ')}.`
                        }}
                    </p>
                    <div
                        v-if="missingDoctorScales.length > 0"
                        class="flex flex-wrap gap-2"
                    >
                        <Button
                            v-for="scale in missingDoctorScales"
                            :key="scale.scale"
                            size="sm"
                            variant="outline"
                            as-child
                        >
                            <Link
                                :href="rateHref(scale.scale)"
                                :data-test="`missing-rate-${scale.scale}`"
                            >
                                <ClipboardPen />
                                {{ `Заполнить ${scale.name}` }}
                            </Link>
                        </Button>
                    </div>
                </div>

                <p class="text-sm text-muted-foreground">
                    Система только подсказывает, решение за вами. Направление
                    оформляется в карточке пациента: сначала он регистрируется
                    по коду на своём телефоне, ответы и HADS переходят в
                    карточку.
                </p>

                <!-- Подсказка выросла после решения: решить снова — главное на блоке. -->
                <p
                    v-if="declinedText && !declinedCurrent"
                    class="rounded-lg border border-warning-border bg-warning-subtle p-3 text-sm"
                    data-test="referral-declined"
                >
                    {{ declinedText }}
                </p>

                <div class="flex flex-wrap gap-2">
                    <AppointmentInvitation
                        variant="default"
                        label="Зарегистрировать пациента"
                        :queue-answer-id="answer.id"
                        :queue-code="answer.code"
                        @registered="onRegistered"
                        @open-change="(value: boolean) => (inviting = value)"
                    />
                    <Button
                        v-if="suggestion.category && !declinedCurrent"
                        variant="outline"
                        data-test="decline"
                        @click="declining = true"
                    >
                        Не направлять
                    </Button>
                </div>

                <p
                    v-if="declinedText && declinedCurrent"
                    class="text-sm text-muted-foreground"
                    data-test="referral-declined"
                >
                    {{ declinedText }}
                </p>
            </div>
        </section>

        <section
            class="flex flex-col gap-3"
            aria-labelledby="answers-title"
            data-test="queue-answers"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="answers-title" class="text-lg font-semibold">Ответы</h2>
                <div v-if="!registered" class="flex flex-wrap gap-2">
                    <Button
                        v-for="scale in ratedScales"
                        :key="scale.code"
                        size="sm"
                        variant="outline"
                        as-child
                    >
                        <Link :href="rateHref(scale.code)">
                            <ClipboardPen />
                            {{ `Заполнить ${scale.name} заново` }}
                        </Link>
                    </Button>
                </div>
            </div>
            <div
                v-for="filling in fillings"
                :key="filling.id"
                class="flex flex-col gap-1 rounded-xl border bg-card p-4 shadow-xs"
                :data-test="`queue-filling-${filling.scale}`"
            >
                <AssessmentAnswers :assessment="filling" />
                <p
                    v-if="filling.rated_by"
                    class="text-sm text-muted-foreground"
                >
                    {{ `Врач: ${filling.rated_by}, ${filling.submitted_at}.` }}
                </p>
                <p
                    v-if="filling.rated_by && factsText"
                    class="text-sm"
                    data-test="queue-facts"
                >
                    {{ `Отмечено: ${factsText}.` }}
                </p>
            </div>
        </section>

        <DeclineReferralDialog
            v-if="suggestion.category"
            v-model:open="declining"
            :action="QueueAnswerController.decline(answer.id)"
            :missing-scales="
                required ? [] : missingDoctorScales.map((scale) => scale.name)
            "
            :suggestion="suggestion.category"
            :max-length="commentLimit"
            @declined="onDecided"
            @close-auto-focus="returnFocus"
        />
    </div>
</template>
