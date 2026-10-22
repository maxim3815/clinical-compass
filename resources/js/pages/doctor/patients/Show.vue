<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    KeyRound,
    MessageCircle,
    OctagonAlert,
    Pencil,
    Plus,
    Siren,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ClinicalProfileController from '@/actions/App/Http/Controllers/Doctor/ClinicalProfileController';
import PasswordResetLinkController from '@/actions/App/Http/Controllers/Doctor/PasswordResetLinkController';
import PrescriptionController from '@/actions/App/Http/Controllers/Doctor/PrescriptionController';
import AdherenceCourse from '@/components/AdherenceCourse.vue';
import AdherenceSignal from '@/components/AdherenceSignal.vue';
import AlertSection from '@/components/AlertSection.vue';
import CancelPrescriptionDialog from '@/components/CancelPrescriptionDialog.vue';
import ClinicalProfileSummary from '@/components/ClinicalProfileSummary.vue';
import CloseAlertDialog from '@/components/CloseAlertDialog.vue';
import ContinueFindingDialog from '@/components/ContinueFindingDialog.vue';
import OneTimeLink from '@/components/OneTimeLink.vue';
import PrescriptionDetails from '@/components/PrescriptionDetails.vue';
import PrescriptionHistory from '@/components/PrescriptionHistory.vue';
import PrescriptionSafety from '@/components/PrescriptionSafety.vue';
import WellbeingSection from '@/components/WellbeingSection.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useRealtime } from '@/composables/useRealtime';
import { alertTitle } from '@/lib/alerts';
import { CHIP_ICON, DANGER_CHIP } from '@/lib/chips';
import { formatAge, plural } from '@/lib/plural';
import { index, show } from '@/routes/doctor/patients';
import { show as chat } from '@/routes/doctor/patients/chat';
import type {
    Adherence,
    AdherenceSignal as Signal,
    AlertDecisionOption,
    ClinicalProfile,
    PatientAlert,
    Prescription,
    SafetyCard,
    SafetyFinding,
    SelectOption,
    Wellbeing,
} from '@/types';

type Patient = {
    id: number;
    name: string;
    login: string;
    birth_date: string | null;
    age: number | null;
    started_at: string;
    timezone: string | null;
};

const props = defineProps<{
    patient: Patient;
    prescriptions: Prescription[];
    ownMedications: Prescription[];
    prescriptionHistory: Prescription[];
    adherence: Adherence;
    adherenceSignal: Signal;
    cancelReasons: SelectOption[];
    clinicalProfile: ClinicalProfile;
    // Алерты по ответам пациента, сначала новые.
    alerts: PatientAlert[];
    alertDecisions: AlertDecisionOption[];
    alertCommentLimit: number;
    // Опросники пациента: суммы, степени, изменение и ответы.
    wellbeing: Wellbeing;
    // Находки и «Не проверено» по id назначения.
    safety: Record<number, SafetyCard>;
    justificationLimit: number;
    // Непрочитанные сообщения пациента.
    unread: number;
}>();

defineOptions({
    layout: (props: { patient: Patient }) => ({
        breadcrumbs: [
            {
                title: 'Пациенты',
                href: index(),
            },
            {
                title: props.patient.name,
                href: show(props.patient.id),
            },
        ],
    }),
});

const page = usePage();
const cancelling = ref<Prescription | null>(null);
const closingAlert = ref<PatientAlert | null>(null);

// Новый, принятый или закрытый алерт — в том числе в другой вкладке: блок алертов обновляется.
useRealtime(
    `App.Models.User.${page.props.auth.user.id}`,
    'alerts.changed',
    () => router.reload({ only: ['alerts'] }),
);

const openAlerts = computed(() =>
    props.alerts.filter((alert) => !alert.closed),
);

// Плашка в шапке — о новом открытом алерте, остальные числом.
const alertChip = computed(() => {
    const [latest] = openAlerts.value;

    if (latest === undefined) {
        return null;
    }

    const state = latest.acknowledged ? 'ждёт решения' : 'не принят';
    const more =
        openAlerts.value.length > 1
            ? ` · ещё ${openAlerts.value.length - 1}`
            : '';

    return `${alertTitle(latest.rule, latest.trigger)} · ${state}${more}`;
});
const continuing = ref<{ finding: SafetyFinding; drug: string } | null>(null);

function continueDespite(
    prescription: Prescription,
    finding: SafetyFinding,
): void {
    continuing.value = { finding, drug: prescription.drug.name };
}
const passwordResetUrl = computed(() => page.flash.passwordResetUrl);

// Легенда цветов дней — у первого регулярного курса раздела.
const legendCourseId = computed(
    () =>
        [
            ...props.adherence.courses,
            ...props.adherence.own,
            ...props.adherence.finished,
        ].find((course) => course.kind === 'regular')?.id,
);

const hasCourses = computed(
    () =>
        props.adherence.courses.length > 0 ||
        props.adherence.finished.length > 0 ||
        props.adherence.own.length > 0,
);

// Кто пациент одной строкой: пол, возраст и дата рождения, с какого числа под наблюдением.
const summary = computed(() => {
    const { birth_date, age, started_at } = props.patient;
    const parts: string[] = [];

    if (props.clinicalProfile.sex !== null) {
        parts.push(
            props.clinicalProfile.sex === 'female' ? 'Женщина' : 'Мужчина',
        );
    }

    if (birth_date !== null) {
        parts.push(
            age === null ? birth_date : `${formatAge(age)} (${birth_date})`,
        );
    }

    parts.push(`под наблюдением с ${started_at}`);

    return parts.join(' · ');
});

const mainDiagnoses = computed(() =>
    props.clinicalProfile.diagnoses.filter((diagnosis) => diagnosis.is_main),
);

// Препараты с противопоказанием, по которому врач ещё не решил: ссылка ведёт к первому.
const awaiting = computed(() =>
    [...props.prescriptions, ...props.ownMedications].filter((prescription) =>
        props.safety[prescription.id]?.findings.some(
            (finding) => finding.awaits_decision,
        ),
    ),
);

// Сочетание двух препаратов даёт находку у обоих, а решение одно: полная плашка — у первого
// по порядку в карточке, у второго — ссылка на неё.
const pairedFindingIds = computed(() => {
    const seen = new Set<string>();
    const paired: number[] = [];

    for (const prescription of [
        ...props.prescriptions,
        ...props.ownMedications,
    ]) {
        for (const finding of props.safety[prescription.id]?.findings ?? []) {
            if (!finding.awaits_decision || finding.related_ids.length === 0) {
                continue;
            }

            const pair = [prescription.id, ...finding.related_ids]
                .sort((a, b) => a - b)
                .join('-');

            if (seen.has(pair)) {
                paired.push(finding.id);
            } else {
                seen.add(pair);
            }
        }
    }

    return paired;
});

function awaitsDecision(prescription: Prescription): boolean {
    return awaiting.value.includes(prescription);
}

const profileHref = computed(() =>
    ClinicalProfileController.edit(props.patient.id),
);
// Ссылки к разделу профиля, где вносят недостающее.
const factsHref = computed(
    () => `${ClinicalProfileController.edit.url(props.patient.id)}#facts`,
);
const diagnosesHref = computed(
    () => `${ClinicalProfileController.edit.url(props.patient.id)}#diagnoses`,
);

// Чем закончились прежние назначения врача: отмена с причиной видна рядом с текущими.
const closedEarlier = computed(() =>
    props.prescriptionHistory.filter(
        (prescription) =>
            prescription.source === 'doctor' &&
            (prescription.status === 'cancelled' ||
                prescription.status === 'completed'),
    ),
);

function closing(prescription: Prescription): string {
    if (prescription.status === 'completed') {
        return 'курс завершён';
    }

    return prescription.cancel_reason === null
        ? `отменено ${prescription.closed_at?.split(' ')[0]}`
        : `отменено ${prescription.closed_at?.split(' ')[0]}: ${prescription.cancel_reason.toLowerCase()}`;
}

// Опросники в шапке: дата и суммы со степенью последнего заполнения и незаполненный срок.
const questionnaires = computed(() => {
    const filled = props.wellbeing.scales.filter(
        (scale) => scale.latest !== null,
    );
    const status = props.wellbeing.status;
    const due = status?.due
        ? `срок ${status.due_since?.slice(0, 5)} не заполнен`
        : null;

    if (filled.length === 0 && due === null) {
        return null;
    }

    return {
        title:
            filled.length === 0
                ? 'Опросники:'
                : `Опросники ${filled[0].latest!.date.slice(0, 5)}:`,
        scores: filled.map((scale, index) => ({
            name: scale.name,
            total: `${scale.name} ${scale.latest!.total}`,
            band: `, ${scale.latest!.band}${index < filled.length - 1 ? ' · ' : ''}`,
        })),
        due: due === null ? null : filled.length === 0 ? due : ` · ${due}`,
    };
});

const unreadLabel = computed(
    () =>
        `${props.unread} ${plural(props.unread, ['новое', 'новых', 'новых'])}`,
);
</script>
<template>
    <Head :title="patient.name" />

    <div class="@container flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <header
            class="flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-xs"
            data-test="patient-details"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 flex-col gap-1">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ patient.name }}
                    </h1>
                    <p class="text-muted-foreground">{{ summary }}</p>
                </div>
                <Button variant="outline" as-child>
                    <Link :href="chat(patient.id)" data-test="chat-button">
                        <MessageCircle />
                        Написать
                        <span
                            v-if="unread > 0"
                            class="rounded-md bg-secondary px-1.5 text-sm font-semibold text-secondary-foreground"
                            >{{ unreadLabel }}</span
                        >
                    </Link>
                </Button>
            </div>

            <p v-if="mainDiagnoses.length > 0" class="flex flex-wrap gap-2">
                <span
                    v-for="diagnosis in mainDiagnoses"
                    :key="diagnosis.id"
                    class="rounded-lg bg-secondary px-2.5 py-1 text-sm text-secondary-foreground"
                >
                    <span class="font-semibold">{{ diagnosis.code }}</span>
                    {{ diagnosis.name }}
                </span>
            </p>
            <p v-else class="text-sm text-muted-foreground">
                Основной диагноз не указан: показания к назначению не
                проверяются.
                <Link
                    :href="diagnosesHref"
                    class="text-primary underline underline-offset-4"
                    >Указать</Link
                >
            </p>

            <!--
                Что требует внимания и как пациент принимает: ссылки ведут к назначению и к приёму
                в этой карточке. Доля за 7 дней видна всегда, предупреждающим цветом — при сигнале.
            -->
            <ul
                v-if="
                    alertChip !== null ||
                    awaiting.length > 0 ||
                    adherenceSignal.raised ||
                    adherenceSignal.share !== null ||
                    questionnaires !== null
                "
                class="flex flex-wrap gap-2 text-sm"
                aria-label="Сводка"
            >
                <li v-if="alertChip">
                    <a
                        href="#alerts"
                        class="hover:underline pointer-coarse:min-h-11 pointer-coarse:items-center"
                        :class="DANGER_CHIP"
                        data-test="card-alert"
                    >
                        <Siren :class="CHIP_ICON" />
                        {{ alertChip }}
                        <ArrowDown class="mt-0.5 size-4 shrink-0" />
                    </a>
                </li>
                <li v-if="awaiting.length > 0">
                    <a
                        :href="`#prescription-${awaiting[0].id}-title`"
                        class="hover:underline pointer-coarse:min-h-11 pointer-coarse:items-center"
                        :class="DANGER_CHIP"
                        data-test="card-contraindication"
                    >
                        <OctagonAlert :class="CHIP_ICON" />
                        Противопоказание без решения:
                        {{
                            awaiting
                                .map((prescription) => prescription.drug.name)
                                .join(', ')
                        }}
                        <ArrowDown class="mt-0.5 size-4 shrink-0" />
                    </a>
                </li>
                <li v-if="adherenceSignal.raised">
                    <a
                        href="#adherence"
                        class="inline-flex items-center gap-1.5 rounded-md hover:underline pointer-coarse:min-h-11"
                    >
                        <TriangleAlert class="size-4 shrink-0 text-warning" />
                        <AdherenceSignal :signal="adherenceSignal" card />
                        <ArrowDown class="size-4 shrink-0 text-warning" />
                    </a>
                </li>
                <li v-else-if="adherenceSignal.share !== null">
                    <a
                        href="#adherence"
                        class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 hover:underline pointer-coarse:min-h-11"
                        data-test="card-adherence"
                    >
                        Приём за 7 дней:
                        <span class="font-semibold tabular-nums"
                            >{{ adherenceSignal.share }}%</span
                        >
                        <ArrowDown class="size-4 shrink-0" />
                    </a>
                </li>
                <li v-if="questionnaires">
                    <a
                        href="#wellbeing"
                        class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 hover:underline pointer-coarse:min-h-11"
                        data-test="card-wellbeing"
                    >
                        <!-- Суммы полужирные: высокая сумма видна сразу. -->
                        <span>
                            {{ `${questionnaires.title} ` }}
                            <template
                                v-for="score in questionnaires.scores"
                                :key="score.name"
                            >
                                <span class="font-semibold tabular-nums">{{
                                    score.total
                                }}</span
                                ><span>{{ score.band }}</span>
                            </template>
                            <span v-if="questionnaires.due">{{
                                questionnaires.due
                            }}</span>
                        </span>
                        <ArrowDown class="size-4 shrink-0" />
                    </a>
                </li>
            </ul>
        </header>

        <!--
            На широком экране две колонки: лечение и приём слева, профиль и доступ справа.
            На узком колонки растворяются (contents), и разделы идут по порядку order.
        -->
        <div
            class="grid grid-cols-[minmax(0,1fr)] items-start gap-6 @4xl:grid-cols-[minmax(0,1fr)_20rem]"
        >
            <div class="flex flex-col gap-6 @max-4xl:contents">
                <!-- Открытый алерт — первым, только закрытые — рядом с историей назначений. -->
                <AlertSection
                    v-if="alerts.length > 0"
                    :patient-id="patient.id"
                    :alerts="alerts"
                    :chat-href="chat(patient.id)"
                    :class="openAlerts.length > 0 ? 'order-0' : 'order-5'"
                    @close="(alert) => (closingAlert = alert)"
                />

                <section
                    class="order-1 flex scroll-mt-20 flex-col gap-3"
                    aria-labelledby="prescriptions-title"
                    data-test="prescriptions"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2
                            id="prescriptions-title"
                            class="text-lg font-semibold"
                        >
                            Назначения
                        </h2>
                        <Button as-child>
                            <Link
                                :href="
                                    PrescriptionController.create(patient.id)
                                "
                                data-test="prescribe-button"
                            >
                                <Plus />
                                Назначить
                            </Link>
                        </Button>
                    </div>

                    <p
                        v-if="prescriptions.length === 0"
                        class="rounded-xl border border-dashed bg-card p-4 text-sm text-muted-foreground"
                    >
                        Назначений пока нет.
                    </p>

                    <PrescriptionDetails
                        v-for="prescription in prescriptions"
                        :key="prescription.id"
                        :prescription="prescription"
                        :attention="awaitsDecision(prescription)"
                        for-doctor
                    >
                        <template v-if="safety[prescription.id]" #safety>
                            <PrescriptionSafety
                                :card="safety[prescription.id]"
                                :changeable="prescription.can_change"
                                :profile-href="factsHref"
                                :chat-href="chat(patient.id)"
                                :paired-ids="pairedFindingIds"
                                :edit-href="
                                    PrescriptionController.edit({
                                        patient: patient.id,
                                        prescription: prescription.id,
                                    })
                                "
                                @continue="
                                    (finding) =>
                                        continueDespite(prescription, finding)
                                "
                                @cancel="cancelling = prescription"
                            />
                        </template>
                        <template v-if="prescription.can_change" #actions>
                            <Button variant="ghost" size="sm" as-child>
                                <Link
                                    :href="
                                        PrescriptionController.edit({
                                            patient: patient.id,
                                            prescription: prescription.id,
                                        })
                                    "
                                    :aria-describedby="`prescription-${prescription.id}-title`"
                                >
                                    <Pencil />
                                    Изменить
                                </Link>
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                :aria-describedby="`prescription-${prescription.id}-title`"
                                data-test="cancel-prescription-button"
                                @click="cancelling = prescription"
                            >
                                Отменить назначение
                            </Button>
                        </template>
                    </PrescriptionDetails>
                </section>

                <p
                    v-if="closedEarlier.length > 0"
                    class="order-1 -mt-3 text-sm text-muted-foreground"
                    data-test="closed-earlier"
                >
                    Ранее:
                    {{
                        closedEarlier
                            .map(
                                (prescription) =>
                                    `${prescription.drug.name} — ${closing(prescription)}`,
                            )
                            .join('; ')
                    }}.
                    <a
                        href="#history"
                        class="text-primary underline underline-offset-4"
                        >История назначений</a
                    >
                </p>

                <section
                    v-if="ownMedications.length > 0"
                    class="order-2 flex flex-col gap-3"
                    aria-labelledby="own-medications-title"
                    data-test="own-medications"
                >
                    <div>
                        <h2
                            id="own-medications-title"
                            class="text-lg font-semibold"
                        >
                            Добавил пациент
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Препараты от других врачей. Изменить их может только
                            пациент.
                        </p>
                    </div>

                    <PrescriptionDetails
                        v-for="prescription in ownMedications"
                        :key="prescription.id"
                        :prescription="prescription"
                        :attention="awaitsDecision(prescription)"
                        for-doctor
                    >
                        <template v-if="safety[prescription.id]" #safety>
                            <PrescriptionSafety
                                :card="safety[prescription.id]"
                                :changeable="false"
                                :profile-href="factsHref"
                                :chat-href="chat(patient.id)"
                                :paired-ids="pairedFindingIds"
                                @continue="
                                    (finding) =>
                                        continueDespite(prescription, finding)
                                "
                            />
                        </template>
                    </PrescriptionDetails>
                </section>

                <section
                    id="adherence"
                    class="order-3 flex scroll-mt-20 flex-col gap-3"
                    aria-labelledby="adherence-title"
                    data-test="adherence"
                >
                    <div>
                        <h2 id="adherence-title" class="text-lg font-semibold">
                            Приём препаратов
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Доля принятых по контрольным периодам курса: дни
                            1–7, 8–14, 15–28, дальше каждые 28 дней.
                        </p>
                    </div>

                    <p
                        v-if="adherenceSignal.raised"
                        class="flex items-center gap-2 text-sm"
                        data-test="adherence-signal"
                    >
                        <TriangleAlert class="size-4 shrink-0 text-warning" />
                        <AdherenceSignal :signal="adherenceSignal" card />
                    </p>

                    <p
                        v-if="adherence.timezone"
                        class="text-sm text-muted-foreground"
                        data-test="adherence-timezone"
                    >
                        Время пациента: {{ adherence.timezone }}
                    </p>

                    <p
                        v-if="!hasCourses"
                        class="rounded-xl border border-dashed bg-card p-4 text-sm text-muted-foreground"
                    >
                        Пациент пока не принимает препараты по назначению.
                    </p>

                    <AdherenceCourse
                        v-for="course in adherence.courses"
                        :key="course.id"
                        :course="course"
                        :legend="course.id === legendCourseId"
                    />

                    <AdherenceCourse
                        v-for="course in adherence.own"
                        :key="course.id"
                        :course="course"
                        :legend="course.id === legendCourseId"
                    />

                    <template v-if="adherence.finished.length > 0">
                        <h3 class="mt-2 font-semibold text-muted-foreground">
                            Завершённые курсы
                        </h3>
                        <AdherenceCourse
                            v-for="course in adherence.finished"
                            :key="course.id"
                            :course="course"
                            :legend="course.id === legendCourseId"
                        />
                    </template>
                </section>

                <WellbeingSection :wellbeing="wellbeing" class="order-3" />

                <section
                    v-if="prescriptionHistory.length > 0"
                    id="history"
                    class="order-5 flex scroll-mt-20 flex-col gap-3"
                    aria-labelledby="history-title"
                    data-test="prescription-history"
                >
                    <div>
                        <h2 id="history-title" class="text-lg font-semibold">
                            История назначений
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Прежние версии изменённых назначений, отменённые и
                            завершённые назначения.
                        </p>
                    </div>

                    <PrescriptionHistory :prescriptions="prescriptionHistory" />
                </section>
            </div>

            <div class="flex flex-col gap-6 @max-4xl:contents">
                <section
                    class="order-4 flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
                    aria-labelledby="clinical-profile-title"
                    data-test="clinical-profile-section"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div>
                            <h2
                                id="clinical-profile-title"
                                class="text-lg font-semibold"
                            >
                                Клинический профиль
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                По нему проверяется безопасность назначений.
                            </p>
                        </div>
                        <Button variant="outline" size="sm" as-child>
                            <Link
                                :href="profileHref"
                                data-test="edit-clinical-profile"
                            >
                                <Pencil />
                                Изменить профиль
                            </Link>
                        </Button>
                    </div>

                    <ClinicalProfileSummary :profile="clinicalProfile" />
                </section>

                <section
                    class="order-6 flex flex-col gap-3 rounded-xl border bg-card p-4 text-sm shadow-xs"
                    aria-labelledby="access-title"
                >
                    <h2 id="access-title" class="text-lg font-semibold">
                        Вход в кабинет
                    </h2>
                    <p>
                        <span class="text-muted-foreground">Логин: </span>
                        <span class="font-medium">{{ patient.login }}</span>
                    </p>
                    <p
                        v-if="!adherence.timezone && patient.timezone"
                        class="text-muted-foreground"
                    >
                        Часовой пояс: {{ patient.timezone }}
                    </p>
                    <p class="text-muted-foreground">
                        Если пациент забыл пароль, дайте ему ссылку: по ней он
                        задаст новый. Ссылка действует 24 часа и срабатывает
                        один раз, новая отменяет прежнюю.
                    </p>

                    <Form
                        v-bind="
                            PasswordResetLinkController.store.form(patient.id)
                        "
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="processing"
                            data-test="password-reset-link-button"
                        >
                            <KeyRound />
                            Ссылка для смены пароля
                        </Button>
                    </Form>

                    <OneTimeLink
                        v-if="passwordResetUrl"
                        :url="passwordResetUrl"
                        label="Ссылка для смены пароля"
                        data-test="password-reset-link"
                    >
                        Отправьте ссылку пациенту. Больше она не покажется: если
                        потеряете, создайте новую.
                    </OneTimeLink>
                </section>
            </div>
        </div>

        <CancelPrescriptionDialog
            v-model:prescription="cancelling"
            :patient-id="patient.id"
            :reasons="cancelReasons"
        />

        <CloseAlertDialog
            v-model:alert="closingAlert"
            :patient-id="patient.id"
            :decisions="alertDecisions"
            :max-length="alertCommentLimit"
        />

        <ContinueFindingDialog
            v-model:finding="continuing"
            :patient-id="patient.id"
            :max-length="justificationLimit"
        />
    </div>
</template>
