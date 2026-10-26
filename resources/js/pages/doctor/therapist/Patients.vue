<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    CircleCheck,
    ExternalLink,
    Printer,
    Search,
    Send,
    Siren,
    UserPlus,
} from '@lucide/vue';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import QueueAnswerController from '@/actions/App/Http/Controllers/Doctor/QueueAnswerController';
import AppointmentInvitation from '@/components/AppointmentInvitation.vue';
import ListPagination from '@/components/ListPagination.vue';
import PushBanner from '@/components/PushBanner.vue';
import ReferralCategoryBadge from '@/components/ReferralCategoryBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useRealtime } from '@/composables/useRealtime';
import { CHIP_ICON, DANGER_CHIP } from '@/lib/chips';
import { formatAge } from '@/lib/plural';
import { index as invitations } from '@/routes/doctor/invitations';
import { index, show } from '@/routes/doctor/patients';
import { poster } from '@/routes/doctor/queue';
import type {
    AnsweredToday,
    InvitationChanged,
    Paginated,
    SentReferral,
    TherapistPatientRow,
} from '@/types';

/**
 * Список пациентов терапевта: кто ответил на опросники сегодня (их смотрят на приёме первыми),
 * кого направили к психиатру и все пациенты. В «Ответили сегодня» и ответы из очереди у двери:
 * без регистрации, с кодом, который пациент называет на приёме.
 */
const props = defineProps<{
    patients: Paginated<TherapistPatientRow>;
    // При поиске пустые: врач ищет конкретного пациента.
    answered: AnsweredToday[];
    referrals: SentReferral[];
    search: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Пациенты', href: index() }],
    },
});

const page = usePage();
const query = ref(props.search);

function submit(): void {
    const search = query.value.trim();

    router.get(
        index.url(),
        { search: search || undefined },
        // Код из очереди уводит на страницу ответов: «Назад» вернёт к списку.
        { preserveState: true, replace: !/^\d{4}$/.test(search) },
    );
}

// Возраст и диагноз одной строкой: текст и <template v-if> подряд на сервере сливаются в один узел.
function details(patient: TherapistPatientRow): string {
    return [
        patient.age === null ? null : formatAge(patient.age),
        patient.diagnosis
            ? `${patient.diagnosis.code} ${patient.diagnosis.name}`
            : null,
        `наблюдается с ${patient.started_at}`,
    ]
        .filter(Boolean)
        .join(' · ');
}

function answeredHref(row: AnsweredToday) {
    return row.kind === 'patient'
        ? show(row.id)
        : QueueAnswerController.show(row.id);
}

// Одной строкой: текст и <template v-if> подряд на сервере сливаются в один узел.
function answeredDetails(row: AnsweredToday): string {
    return [
        row.kind === 'queue' ? 'из очереди, без регистрации' : null,
        row.kind === 'patient' && row.age !== null ? formatAge(row.age) : null,
        `ответы в ${row.at}`,
        // Какие коды терапевт уже смотрел: к вечеру строк с кодами много.
        row.kind === 'queue'
            ? row.opened_at
                ? `открыто в ${row.opened_at}`
                : 'не открыто'
            : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

function referralStatus(referral: SentReferral): string {
    return referral.status === 'accepted'
        ? `принято психиатром ${referral.accepted_at}: ${referral.to}`
        : `направление ждёт психиатра: ${referral.to}, отправлено ${referral.created_at}`;
}

const channel = `App.Models.User.${page.props.auth.user.id}`;

// Пациент ответил на опросники или психиатр принял направление: списки меняются.
useRealtime(channel, 'patient-signals.changed', () =>
    router.reload({ only: ['patients', 'answered', 'referrals'] }),
);

// Новые ответы из очереди, HADS по ним, решение или регистрация.
useRealtime(channel, 'queue-answers.changed', () =>
    router.reload({ only: ['answered'] }),
);

// Новый код в списке объявляется скринридеру: строки приходят без перезагрузки.
const announcement = ref('');

watch(
    () => props.answered,
    (now, before) => {
        const known = new Set(
            before.filter((row) => row.kind === 'queue').map((row) => row.id),
        );
        const fresh = now.filter(
            (row) => row.kind === 'queue' && !known.has(row.id),
        );

        if (fresh.length > 0) {
            announcement.value = '';
            void nextTick(() => {
                announcement.value = `Новые ответы из очереди: ${fresh
                    .map((row) =>
                        row.kind === 'queue'
                            ? `код ${row.code.split('').join(' ')}`
                            : '',
                    )
                    .join(', ')}.`;
            });
        }
    },
);

// Событие могло пропасть, пока вкладка была в фоне или пропадал интернет: при возврате
// список обновляется.
function refreshOnReturn(): void {
    if (document.visibilityState === 'visible' && !props.search) {
        router.reload({ only: ['answered', 'patients', 'referrals'] });
    }
}

onMounted(() => document.addEventListener('visibilitychange', refreshOnReturn));
onBeforeUnmount(() =>
    document.removeEventListener('visibilitychange', refreshOnReturn),
);

useRealtime(channel, 'alerts.changed', () =>
    router.reload({
        only: ['patients', 'answered', 'urgentAlerts', 'patientSignals'],
    }),
);

useRealtime<InvitationChanged>(channel, 'invitation.changed', (event) => {
    if (event.stage === 'registered') {
        router.reload({ only: ['patients', 'answered'] });
    }
});
</script>

<template>
    <Head title="Пациенты" />

    <p class="sr-only" aria-live="polite" aria-atomic="true">
        {{ announcement }}
    </p>

    <div class="@container flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight">Пациенты</h1>
            <div class="flex flex-wrap gap-2">
                <AppointmentInvitation
                    :variant="
                        patients.total === 0 && !search ? 'default' : 'outline'
                    "
                />
                <Button variant="outline" as-child>
                    <Link :href="invitations()">
                        <UserPlus />
                        Пригласить по ссылке
                    </Link>
                </Button>
                <!-- В новой вкладке: печать не уводит со списка. С телефона плакат не печатают. -->
                <Button variant="outline" class="max-sm:hidden" as-child>
                    <a
                        :href="poster().url"
                        target="_blank"
                        rel="noopener"
                        data-test="queue-poster-link"
                    >
                        <Printer />
                        Плакат для двери
                        <ExternalLink class="size-3.5" />
                        <span class="sr-only">(в новой вкладке)</span>
                    </a>
                </Button>
            </div>
        </header>

        <PushBanner />

        <div
            v-if="patients.total === 0 && !search && answered.length === 0"
            class="rounded-xl border border-dashed bg-card p-6"
            data-test="patients-empty"
        >
            <p class="text-muted-foreground">
                Пациентов пока нет. Повесьте у двери «Плакат для двери» (печать
                с компьютера): пациенты в очереди ответят на вопросы о
                самочувствии, и ответы с кодами появятся здесь сами. Чтобы
                направить пациента, на приёме нажмите «Пригласить на приёме»: он
                зарегистрируется по коду.
            </p>
        </div>

        <template v-else>
            <!-- Поиск над списками: пациент называет код, а строки с кодами — первые на странице. -->
            <form
                role="search"
                class="flex w-full gap-2 sm:max-w-md"
                @submit.prevent="submit"
            >
                <Input
                    v-model="query"
                    type="search"
                    name="search"
                    placeholder="Поиск по ФИО или коду"
                    aria-label="Поиск по ФИО или коду из очереди"
                />
                <Button type="submit" variant="outline">
                    <Search />
                    Найти
                </Button>
            </form>

            <template v-if="!search">
                <section
                    class="flex flex-col gap-3"
                    aria-labelledby="answered-title"
                    data-test="answered-today"
                >
                    <div>
                        <h2 id="answered-title" class="text-lg font-semibold">
                            Ответили сегодня
                            <span
                                v-if="answered.length > 0"
                                class="font-normal text-muted-foreground"
                                >· {{ answered.length }}</span
                            >
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            GAD-7 и PHQ-9, которые пациент заполнил сегодня, и
                            подсказка направления к психиатру. Строки с кодом —
                            ответы из очереди у двери: откройте ту, код которой
                            назовёт пациент.
                        </p>
                    </div>

                    <p
                        v-if="answered.length === 0"
                        class="rounded-xl border border-dashed bg-card p-4 text-sm text-muted-foreground"
                        data-test="answered-empty"
                    >
                        Сегодня ещё никто не ответил. Новые ответы появятся
                        здесь сами.
                    </p>

                    <ul
                        v-else
                        class="divide-y rounded-xl border bg-card shadow-xs"
                    >
                        <li
                            v-for="row in answered"
                            :key="`${row.kind}-${row.id}`"
                            class="relative flex flex-col gap-2 p-4 transition-colors hover:bg-accent/50 has-[a:focus-visible]:bg-accent/50 @2xl:flex-row @2xl:items-start @2xl:justify-between @2xl:gap-6"
                            :data-test="
                                row.kind === 'queue'
                                    ? 'answered-queue-row'
                                    : 'answered-row'
                            "
                        >
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <Link
                                    :href="answeredHref(row)"
                                    class="font-semibold underline-offset-4 after:absolute after:inset-0 hover:underline"
                                >
                                    <template v-if="row.kind === 'patient'">{{
                                        row.name
                                    }}</template>
                                    <span v-else class="tabular-nums">{{
                                        `Код ${row.code}`
                                    }}</span>
                                </Link>
                                <p class="text-sm text-muted-foreground">
                                    {{ answeredDetails(row) }}
                                </p>
                                <!-- Шкала переносится целиком, а не посреди «умеренная депрессия». -->
                                <p class="flex flex-wrap gap-x-1 text-sm">
                                    <span
                                        v-for="(scale, position) in row.scales"
                                        :key="scale.name"
                                        >{{
                                            `${position > 0 ? '· ' : ''}${scale.name}: ${scale.total} из ${scale.max}, ${scale.band}`
                                        }}</span
                                    >
                                </p>
                            </div>
                            <ul
                                class="flex flex-wrap items-center gap-2 text-sm @2xl:max-w-[50%] @2xl:justify-end"
                                aria-label="Внимание"
                            >
                                <li
                                    v-if="row.alert"
                                    :class="DANGER_CHIP"
                                    data-test="answered-alert"
                                >
                                    <Siren :class="CHIP_ICON" />
                                    <span>Алерт</span>
                                </li>
                                <!-- Разобранный пациент: вместо подсказки — решение терапевта. -->
                                <li
                                    v-if="row.decision?.kind === 'referred'"
                                    class="flex flex-wrap items-center gap-1.5 text-muted-foreground"
                                    data-test="answered-referred"
                                >
                                    <Send
                                        class="size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span>Направлено</span>
                                    <ReferralCategoryBadge
                                        v-if="row.decision.category"
                                        :category="row.decision.category"
                                        prefix=""
                                        muted
                                    />
                                </li>
                                <li
                                    v-else-if="
                                        row.decision?.kind === 'declined'
                                    "
                                    class="text-muted-foreground"
                                    data-test="answered-declined"
                                >
                                    Решено: не направлять
                                </li>
                                <template v-else>
                                    <li v-if="row.suggestion">
                                        <ReferralCategoryBadge
                                            :category="row.suggestion"
                                            prefix="Подсказка"
                                        />
                                    </li>
                                    <li
                                        v-else
                                        class="flex items-center gap-1.5 text-muted-foreground"
                                    >
                                        <CircleCheck
                                            class="size-4 shrink-0 text-success"
                                            aria-hidden="true"
                                        />
                                        Направление не подсказывается
                                    </li>
                                    <!-- При «обязательно» HADS подсказку уже не изменит. -->
                                    <li
                                        v-if="
                                            row.scales_missing.length > 0 &&
                                            row.suggestion !== 'required'
                                        "
                                        class="text-muted-foreground"
                                        data-test="answered-missing"
                                    >
                                        {{
                                            `нет ${row.scales_missing.join(', ')}: подсказка может измениться`
                                        }}
                                    </li>
                                </template>
                            </ul>
                        </li>
                    </ul>
                </section>

                <section
                    v-if="referrals.length > 0"
                    class="flex flex-col gap-3"
                    aria-labelledby="referrals-title"
                    data-test="sent-referrals"
                >
                    <div>
                        <h2 id="referrals-title" class="text-lg font-semibold">
                            Направлены к психиатру
                            <span class="font-normal text-muted-foreground"
                                >· {{ referrals.length }}</span
                            >
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Пока психиатр не принял пациента, его ведёте вы.
                            Принятых пациентов ведёт психиатр, их карточки вам
                            больше не видны.
                        </p>
                    </div>
                    <ul class="divide-y rounded-xl border bg-card shadow-xs">
                        <li
                            v-for="referral in referrals"
                            :key="referral.id"
                            class="relative flex flex-col gap-2 p-4 @2xl:flex-row @2xl:items-start @2xl:justify-between @2xl:gap-6"
                            :class="
                                referral.status === 'open'
                                    ? 'transition-colors hover:bg-accent/50 has-[a:focus-visible]:bg-accent/50'
                                    : ''
                            "
                            :data-test="`sent-referral-${referral.status}`"
                        >
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <Link
                                    v-if="referral.status === 'open'"
                                    :href="show(referral.patient.id)"
                                    class="font-semibold underline-offset-4 after:absolute after:inset-0 hover:underline"
                                >
                                    {{ referral.patient.name }}
                                </Link>
                                <p v-else class="font-semibold">
                                    {{ referral.patient.name }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ referralStatus(referral) }}
                                </p>
                            </div>
                            <span class="flex items-center gap-2 self-start">
                                <span
                                    v-if="referral.status === 'accepted'"
                                    class="flex items-center gap-1 text-sm text-muted-foreground"
                                >
                                    <CircleCheck
                                        class="size-4 shrink-0 text-success"
                                        aria-hidden="true"
                                    />
                                    принято
                                </span>
                                <span class="sr-only">Категория:</span>
                                <ReferralCategoryBadge
                                    :category="referral.category"
                                    prefix=""
                                    class="text-sm"
                                />
                            </span>
                        </li>
                    </ul>
                </section>
            </template>

            <section
                class="flex flex-col gap-3"
                aria-labelledby="all-patients-title"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="all-patients-title" class="text-lg font-semibold">
                        {{ search ? 'Результаты поиска' : 'Все пациенты' }}
                        <span class="font-normal text-muted-foreground"
                            >· {{ patients.total }}</span
                        >
                    </h2>
                </div>

                <p
                    v-if="patients.total === 0 && !search"
                    class="text-muted-foreground"
                    data-test="patients-none"
                >
                    Зарегистрированных пациентов пока нет. Пациент появится
                    здесь, когда зарегистрируется на приёме по коду.
                </p>
                <p
                    v-else-if="patients.total === 0"
                    class="text-muted-foreground"
                >
                    {{
                        /^\d{4}$/.test(search)
                            ? `Ответов из очереди с кодом ${search} нет: код назван неверно, ответы уже удалены или пациент зарегистрирован — найдите его по ФИО.`
                            : `По запросу «${search}» никого не нашли.`
                    }}
                    <Link
                        :href="index()"
                        class="text-foreground underline underline-offset-4"
                    >
                        Показать всех
                    </Link>
                </p>

                <template v-else>
                    <ul class="divide-y rounded-xl border bg-card shadow-xs">
                        <li
                            v-for="patient in patients.data"
                            :key="patient.id"
                            class="relative flex flex-col gap-2 p-4 transition-colors hover:bg-accent/50 has-[a:focus-visible]:bg-accent/50 @2xl:flex-row @2xl:items-center @2xl:justify-between @2xl:gap-6"
                            data-test="patient-row"
                        >
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <Link
                                    :href="show(patient.id)"
                                    class="font-medium underline-offset-4 after:absolute after:inset-0 hover:underline"
                                >
                                    {{ patient.name }}
                                </Link>
                                <p class="text-sm text-muted-foreground">
                                    {{ details(patient) }}
                                </p>
                            </div>
                            <span
                                v-if="patient.alert || patient.referred"
                                class="flex flex-wrap items-center gap-1.5 text-sm"
                            >
                                <span
                                    v-if="patient.alert"
                                    :class="DANGER_CHIP"
                                    data-test="alert-mark"
                                >
                                    <Siren :class="CHIP_ICON" />
                                    Алерт
                                </span>
                                <span
                                    v-if="patient.referred"
                                    class="text-muted-foreground"
                                    data-test="referred-mark"
                                    >направление ждёт психиатра</span
                                >
                            </span>
                        </li>
                    </ul>

                    <ListPagination :page="patients" />
                </template>
            </section>
        </template>
    </div>
</template>
