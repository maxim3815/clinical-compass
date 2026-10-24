<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CircleCheck, Search, Send, Siren, UserPlus } from '@lucide/vue';
import { ref } from 'vue';
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
import type {
    AnsweredToday,
    InvitationChanged,
    Paginated,
    SentReferral,
    TherapistPatientRow,
} from '@/types';

/**
 * Список пациентов терапевта: кто ответил на опросники сегодня (их смотрят на приёме первыми),
 * кого направили к психиатру и все пациенты.
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
        { preserveState: true, replace: true },
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
            </div>
        </header>

        <PushBanner />

        <div
            v-if="patients.total === 0 && !search"
            class="rounded-xl border border-dashed bg-card p-6"
            data-test="patients-empty"
        >
            <p class="text-muted-foreground">
                Пациентов пока нет. На приёме нажмите «Пригласить на приёме»:
                пациент подключится по коду, ответит на вопросы о самочувствии
                на своём телефоне и появится здесь.
            </p>
        </div>

        <template v-else>
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
                            подсказка направления к психиатру.
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
                            :key="row.id"
                            class="relative flex flex-col gap-2 p-4 transition-colors hover:bg-accent/50 has-[a:focus-visible]:bg-accent/50 @2xl:flex-row @2xl:items-start @2xl:justify-between @2xl:gap-6"
                            data-test="answered-row"
                        >
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <Link
                                    :href="show(row.id)"
                                    class="font-semibold underline-offset-4 after:absolute after:inset-0 hover:underline"
                                >
                                    {{ row.name }}
                                </Link>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        [
                                            row.age === null
                                                ? null
                                                : formatAge(row.age),
                                            `ответы в ${row.at}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </p>
                                <p class="text-sm">
                                    {{
                                        row.scales
                                            .map(
                                                (scale) =>
                                                    `${scale.name}: ${scale.total} из ${scale.max}, ${scale.band}`,
                                            )
                                            .join(' · ')
                                    }}
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
                                    <li
                                        v-if="row.scales_missing.length > 0"
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
                    <form
                        role="search"
                        class="flex w-full gap-2 sm:w-auto"
                        @submit.prevent="submit"
                    >
                        <Input
                            v-model="query"
                            type="search"
                            name="search"
                            class="sm:w-72"
                            placeholder="Поиск по ФИО"
                            aria-label="Поиск по ФИО"
                        />
                        <Button type="submit" variant="outline">
                            <Search />
                            Найти
                        </Button>
                    </form>
                </div>

                <p v-if="patients.total === 0" class="text-muted-foreground">
                    По запросу «{{ search }}» никого не нашли.
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
