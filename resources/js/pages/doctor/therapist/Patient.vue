<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CircleCheck, ClipboardPen, Send } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import DoctorScaleController from '@/actions/App/Http/Controllers/Doctor/DoctorScaleController';
import ReferralController from '@/actions/App/Http/Controllers/Doctor/ReferralController';
import AlertSection from '@/components/AlertSection.vue';
import ClinicalProfileSummary from '@/components/ClinicalProfileSummary.vue';
import CloseAlertDialog from '@/components/CloseAlertDialog.vue';
import CurrentMedications from '@/components/CurrentMedications.vue';
import DeclineReferralDialog from '@/components/DeclineReferralDialog.vue';
import ReferPatientDialog from '@/components/ReferPatientDialog.vue';
import ReferralBasis from '@/components/ReferralBasis.vue';
import ReferralCategoryBadge from '@/components/ReferralCategoryBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import WellbeingSection from '@/components/WellbeingSection.vue';
import { useRealtime } from '@/composables/useRealtime';
import { formatAge } from '@/lib/plural';
import { index, show } from '@/routes/doctor/patients';
import type {
    AlertDecisionOption,
    ClinicalProfile,
    PatientAlert,
    Prescription,
    RatableScale,
    Referral,
    ReferralDeclined,
    ReferralSuggestion,
    Wellbeing,
} from '@/types';

/**
 * Карточка пациента у терапевта: шкалы пациента, HARS, подсказка направления к психиатру
 * и решение терапевта. Назначений, приёма и чата здесь нет: лечение ведёт психиатр.
 */
type Props = {
    patient: {
        id: number;
        name: string;
        login: string;
        birth_date: string | null;
        age: number | null;
        started_at: string;
    };
    alerts: PatientAlert[];
    alertDecisions: AlertDecisionOption[];
    alertCommentLimit: number;
    wellbeing: Wellbeing;
    doctorScales: RatableScale[];
    suggestion: ReferralSuggestion;
    referral: Referral | null;
    declined: ReferralDeclined | null;
    psychiatrists: { id: number; name: string }[];
    commentLimit: number;
    clinicalProfile: ClinicalProfile;
    medications: Prescription[];
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: Props) => ({
        breadcrumbs: [
            { title: 'Пациенты', href: index() },
            { title: props.patient.name, href: show(props.patient.id) },
        ],
    }),
});

const page = usePage();
const closingAlert = ref<PatientAlert | null>(null);
const referring = ref(false);
const declining = ref(false);
const withdrawing = ref(false);
const withdrawProcessing = ref(false);

const summary = computed(() =>
    [
        props.patient.age === null ? null : formatAge(props.patient.age),
        props.patient.birth_date
            ? `дата рождения ${props.patient.birth_date}`
            : null,
        `наблюдается с ${props.patient.started_at}`,
        `логин ${props.patient.login}`,
    ]
        .filter(Boolean)
        .join(' · '),
);

const missingPatientScales = computed(() =>
    props.suggestion.missing.filter((scale) => scale.rater === 'patient'),
);

const missingDoctorScales = computed(() =>
    props.suggestion.missing.filter((scale) => scale.rater === 'doctor'),
);

// «Не направлять» решали при другой подсказке: HARS или новые ответы её изменили.
const declinedStale = computed(
    () =>
        props.declined !== null &&
        props.declined.suggested_category !== props.suggestion.category,
);

const declinedText = computed(() => {
    if (props.declined === null) {
        return null;
    }

    const comment = props.declined.comment
        ? `: «${props.declined.comment}»`
        : '';

    if (declinedStale.value) {
        const was =
            props.declined.suggested_category === 'required'
                ? '«обязательно»'
                : props.declined.suggested_category === 'desirable'
                  ? '«желательно»'
                  : 'без подсказки';

        return `${props.declined.at} вы решили не направлять при подсказке ${was}${comment}. С тех пор подсказка изменилась: решите снова.`;
    }

    return `${props.declined.at} вы решили не направлять${comment}.`;
});

// Кнопка, открывшая окно, после решения исчезает вместе с блоком: фокус — на заголовок блока.
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

function withdraw(): void {
    if (props.referral === null) {
        return;
    }

    router.post(
        ReferralController.withdraw(props.referral.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (withdrawProcessing.value = true),
            onFinish: () => (withdrawProcessing.value = false),
            onSuccess: () => {
                decided = true;
                withdrawing.value = false;
            },
        },
    );
}

// Пациент отправил опросники или психиатр принял направление: подсказка и статус меняются.
const channel = `App.Models.User.${page.props.auth.user.id}`;

useRealtime(channel, 'patient-signals.changed', () =>
    router.reload({
        only: ['wellbeing', 'suggestion', 'referral', 'declined', 'alerts'],
    }),
);

useRealtime(channel, 'alerts.changed', () =>
    router.reload({ only: ['alerts', 'urgentAlerts', 'patientSignals'] }),
);
</script>

<template>
    <Head :title="patient.name" />

    <div class="@container flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ patient.name }}
            </h1>
            <p class="text-sm text-muted-foreground">{{ summary }}</p>
        </header>

        <div
            class="grid grid-cols-[minmax(0,1fr)] items-start gap-6 @4xl:grid-cols-[minmax(0,1fr)_20rem]"
        >
            <div class="flex min-w-0 flex-col gap-6">
                <AlertSection
                    v-if="alerts.length > 0"
                    :patient-id="patient.id"
                    :alerts="alerts"
                    @close="(alert) => (closingAlert = alert)"
                />

                <section
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
                        v-if="referral"
                        class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
                        data-test="referral-open"
                    >
                        <ReferralCategoryBadge
                            :category="referral.category"
                            prefix=""
                            large
                            class="self-start"
                        />
                        <p>
                            {{
                                `Отправлено ${referral.created_at}, психиатр ${referral.to.name}. Направление ждёт, пока психиатр примет пациента. До этого пациента ведёте вы.`
                            }}
                        </p>
                        <p v-if="referral.comment" class="text-sm">
                            <span class="text-muted-foreground"
                                >Ваш комментарий:
                            </span>
                            {{ referral.comment }}
                        </p>
                        <Button
                            variant="outline"
                            class="self-start"
                            data-test="withdraw-referral"
                            @click="withdrawing = true"
                        >
                            Отозвать направление
                        </Button>
                    </div>

                    <div
                        v-else
                        class="flex flex-col gap-4 rounded-xl border bg-card p-4 shadow-xs"
                        data-test="referral-suggestion"
                    >
                        <div
                            v-if="suggestion.category"
                            class="flex flex-col gap-2"
                        >
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
                                    suggestion.category === 'required'
                                        ? `${missingDoctorScales.map((scale) => scale.name).join(', ')} сегодня не заполнена. Подсказку она уже не изменит: выше «обязательно» категории нет. Заполнить можно для психиатра.`
                                        : `${missingDoctorScales.map((scale) => scale.name).join(', ')} сегодня не заполнена: правила по ней не проверены, подсказка может стать выше.`
                                }}
                            </p>
                            <p v-if="missingPatientScales.length > 0">
                                {{
                                    `Нет ответов пациента за последние 14 дней: ${missingPatientScales.map((scale) => scale.name).join(', ')}. Пациент отвечает в приложении на экране «Сегодня».`
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
                                        :href="
                                            DoctorScaleController.create({
                                                patient: patient.id,
                                                scale: scale.scale,
                                            })
                                        "
                                        :data-test="`missing-rate-${scale.scale}`"
                                    >
                                        <ClipboardPen />
                                        {{ `Заполнить ${scale.name}` }}
                                    </Link>
                                </Button>
                            </div>
                        </div>

                        <p class="text-sm text-muted-foreground">
                            Система только подсказывает, решение за вами. Если
                            оно расходится с подсказкой, напишите почему.
                        </p>

                        <p
                            v-if="declinedText && declinedStale"
                            class="rounded-lg border border-warning-border bg-warning-subtle p-3 text-sm"
                            data-test="referral-declined"
                        >
                            {{ declinedText }}
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Button data-test="refer" @click="referring = true">
                                <Send />
                                Направить к психиатру
                            </Button>
                            <Button
                                v-if="suggestion.category"
                                variant="outline"
                                data-test="decline"
                                @click="declining = true"
                            >
                                Не направлять
                            </Button>
                        </div>

                        <p
                            v-if="declinedText && !declinedStale"
                            class="text-sm text-muted-foreground"
                            data-test="referral-declined"
                        >
                            {{ declinedText }}
                        </p>
                    </div>
                </section>

                <!-- Пока HARS сегодня нет, кнопка заполнения — в блоке направления, а не здесь. -->
                <WellbeingSection
                    :wellbeing="wellbeing"
                    :patient-id="patient.id"
                    :rate-scales="
                        missingDoctorScales.length > 0 ? [] : doctorScales
                    "
                />
            </div>

            <aside class="flex min-w-0 flex-col gap-6">
                <section
                    class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
                    aria-labelledby="profile-title"
                >
                    <h2 id="profile-title" class="font-semibold">
                        Клинический профиль
                    </h2>
                    <ClinicalProfileSummary :profile="clinicalProfile" />
                </section>
                <section
                    class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
                    aria-labelledby="medications-title"
                >
                    <h2 id="medications-title" class="font-semibold">
                        Препараты
                    </h2>
                    <CurrentMedications :medications="medications" />
                </section>
            </aside>
        </div>

        <ReferPatientDialog
            v-model:open="referring"
            :patient-id="patient.id"
            :patient-name="patient.name"
            :suggestion="suggestion.category"
            :psychiatrists="psychiatrists"
            :missing-scales="missingDoctorScales"
            :max-length="commentLimit"
            @referred="onDecided"
            @close-auto-focus="returnFocus"
        />

        <DeclineReferralDialog
            v-if="suggestion.category"
            v-model:open="declining"
            :action="ReferralController.decline(patient.id)"
            :missing-scales="
                suggestion.category === 'required'
                    ? []
                    : missingDoctorScales.map((scale) => scale.name)
            "
            :suggestion="suggestion.category"
            :max-length="commentLimit"
            @declined="onDecided"
            @close-auto-focus="returnFocus"
        />

        <Dialog
            :open="withdrawing"
            @update:open="(value: boolean) => (withdrawing = value)"
        >
            <DialogContent
                data-test="withdraw-referral-dialog"
                @close-auto-focus="returnFocus"
            >
                <DialogHeader>
                    <DialogTitle>Отозвать направление?</DialogTitle>
                    <DialogDescription>
                        Психиатр больше не увидит его в очереди. Пациента
                        по-прежнему ведёте вы.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline">Не отзывать</Button>
                    </DialogClose>
                    <Button
                        :disabled="withdrawProcessing"
                        :aria-busy="withdrawProcessing"
                        data-test="confirm-withdraw-referral"
                        @click="withdraw"
                    >
                        <Spinner v-if="withdrawProcessing" />
                        Отозвать
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <CloseAlertDialog
            v-model:alert="closingAlert"
            :patient-id="patient.id"
            :decisions="alertDecisions"
            :max-length="alertCommentLimit"
        />
    </div>
</template>
