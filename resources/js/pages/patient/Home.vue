<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    CalendarClock,
    CalendarDays,
    ChevronRight,
    MessageCircle,
    Stethoscope,
    Thermometer,
    UserRoundX,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
} from 'vue';
import AsNeededCard from '@/components/AsNeededCard.vue';
import DoseGroupCard from '@/components/DoseGroupCard.vue';
import TimeSetupCard from '@/components/TimeSetupCard.vue';
import DoseMarkDialog from '@/components/DoseMarkDialog.vue';
import HelpCard from '@/components/HelpCard.vue';
import InstallAppBanner from '@/components/InstallAppBanner.vue';
import PushBanner from '@/components/PushBanner.vue';
import QuestionnaireCard from '@/components/QuestionnaireCard.vue';
import ReferralCard from '@/components/ReferralCard.vue';
import SideEffectAlertCard from '@/components/SideEffectAlertCard.vue';
import TodaySummary from '@/components/TodaySummary.vue';
import { Button } from '@/components/ui/button';
import { calendar, home } from '@/routes/patient';
import { show as chat } from '@/routes/patient/chat';
import { create as reportSideEffect } from '@/routes/patient/side-effects';
import type {
    AsNeeded,
    AssessmentStatus,
    Dose,
    DoseGroup,
    PatientReferral,
    SelectOption,
} from '@/types';

type Doctor = {
    name: string;
    specialty: string | null;
};

// Назначение, у которого есть приём без выбранного времени.
type TimeSetup = {
    id: number;
    title: string;
};

const props = defineProps<{
    doctor: Doctor | null;
    timeSetup: TimeSetup[];
    hasPrescriptions: boolean;
    today: {
        date: string;
        groups: DoseGroup[];
    };
    yesterday: {
        groups: DoseGroup[];
    };
    asNeeded: AsNeeded[];
    skipReasons: SelectOption[];
    // Опросники по календарю; null — опросников нет или пациент не прикреплён к врачу.
    assessments: AssessmentStatus | null;
    // Карточка с 112 и кризисной линией, пока врач не закрыл алерт по ответам или по «Сообщить врачу».
    help: boolean;
    // Пока открыт алерт по срочному побочному эффекту: о чём сообщил пациент.
    sideEffectAlert: {
        symptoms: string[];
        at: string;
        emergency: boolean;
    } | null;
    // Ссылка «Сообщить о побочном эффекте»: есть лечащий врач.
    canReportSideEffects: boolean;
    // Лица настроения после отметки: только у пациента психиатра.
    moodEnabled: boolean;
    // Сегодня «не принял» из-за самочувствия и ещё ни о чём не сообщил.
    unwellSuggestion: boolean;
    hasIntakeHistory: boolean;
    // Направление терапевта к психиатру: ждёт психиатра или принято на этой неделе.
    referral: PatientReferral | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Сегодня',
                href: home(),
            },
        ],
    },
});

const page = usePage();

// ФИО хранится одной строкой «Фамилия Имя Отчество»: обращаемся по имени и отчеству.
const greetingName = computed(() => {
    const parts = page.props.auth.user.name.trim().split(/\s+/);

    return parts.length > 1 ? parts.slice(1).join(' ') : parts[0];
});

// Вчерашние приёмы показываются и после отметки сегодня, чтобы её можно было исправить.
const yesterdayUnmarked = computed(() =>
    props.yesterday.groups.some((group) =>
        group.doses.some(
            (dose) => dose.status === 'scheduled' || dose.status === 'missed',
        ),
    ),
);

// Пока ни один приём не пора принять, карточка «Настройте время приёма» стоит над расписанием:
// иначе пациент может до неё не долистать, и препарат так и останется без напоминаний.
const setupFirst = computed(
    () =>
        !props.today.groups.some((group) =>
            group.doses.some((dose) => dose.due && dose.status === 'scheduled'),
        ),
);

// Приём, который пациент отмечает в диалоге, и с какой отметки диалог открыт.
const markedDose = ref<Dose | null>(null);
const markStatus = ref<'taken' | 'skipped'>('taken');

function openMark(dose: Dose, status: 'taken' | 'skipped'): void {
    markStatus.value = status;
    markedDose.value = dose;
}

// Переход из напоминания (`?doses=12,13`): карточка с этими приёмами выделена и на экране.
const highlighted = ref<number[]>([]);
const content = useTemplateRef<HTMLElement>('content');
let stopKeepingInView: (() => void) | null = null;

function isHighlighted(group: DoseGroup): boolean {
    return group.doses.some((dose) => highlighted.value.includes(dose.id));
}

onMounted(async () => {
    highlighted.value = (
        new URLSearchParams(window.location.search).get('doses') ?? ''
    )
        .split(',')
        .map(Number)
        .filter((id) => id > 0);

    if (highlighted.value.length === 0) {
        return;
    }

    await nextTick();
    const card = document.querySelector(
        '[data-test="dose-group"][data-highlighted="true"]',
    );

    if (card !== null && content.value !== null) {
        stopKeepingInView = keepInView(card, content.value);
    }
});

onBeforeUnmount(() => stopKeepingInView?.());

/**
 * Высота страницы над карточкой может измениться и после загрузки: подгружается шрифт,
 * появляются баннеры. Пока пациент сам не трогает страницу, карточка возвращается в центр
 * экрана при каждом изменении высоты.
 */
function keepInView(card: Element, container: HTMLElement): () => void {
    const events = ['pointerdown', 'wheel', 'keydown'] as const;
    const observer = new ResizeObserver(() =>
        card.scrollIntoView({ block: 'center' }),
    );
    let timer: ReturnType<typeof setTimeout> | undefined;

    const stop = (): void => {
        observer.disconnect();
        clearTimeout(timer);
        events.forEach((name) => window.removeEventListener(name, stop));
    };

    // Первый вызов наблюдателя сразу прокручивает к карточке.
    observer.observe(container);
    events.forEach((name) =>
        window.addEventListener(name, stop, { passive: true }),
    );
    timer = setTimeout(stop, 10_000);

    return stop;
}
</script>

<template>
    <Head title="Сегодня" />

    <!--
        Сверху вниз: итог дня, ссылки на календарь приёма и на побочные эффекты, куда звонить, если тяжело
        (после ответов, на которые сработал алерт), что пациент срочно сообщил врачу, предложение рассказать
        о самочувствии после пропуска, приёмы на сегодня, что нужно настроить, опросники, если пора их
        заполнить, вчерашние приёмы без отметки и «по необходимости». Баннеры напоминаний
        и установки идут после приёмов, чтобы не сдвигать их с первого экрана.
    -->
    <div ref="content" class="flex flex-col gap-6 p-4">
        <header class="flex flex-col gap-3">
            <div class="flex flex-col gap-1">
                <p class="text-lg text-muted-foreground">
                    Здравствуйте, {{ greetingName }}!
                </p>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Сегодня, {{ today.date }}
                </h1>
            </div>
            <TodaySummary v-if="today.groups.length" :groups="today.groups" />
            <div
                v-if="
                    hasIntakeHistory ||
                    (canReportSideEffects && hasPrescriptions)
                "
                class="flex flex-col"
            >
                <Link
                    v-if="hasIntakeHistory"
                    :href="calendar()"
                    class="-mx-2 flex min-h-11 items-center gap-2 self-start rounded-lg px-2 font-medium text-primary outline-none hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                    data-test="calendar-link"
                >
                    <CalendarDays class="size-5 shrink-0" />
                    Календарь приёма
                    <ChevronRight class="size-5 shrink-0" />
                </Link>
                <Link
                    v-if="canReportSideEffects && hasPrescriptions"
                    :href="reportSideEffect()"
                    class="-mx-2 flex min-h-11 items-center gap-2 self-start rounded-lg px-2 font-medium text-primary outline-none hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                    data-test="report-side-effect-link"
                >
                    <Thermometer class="size-5 shrink-0" />
                    Сообщить о побочном эффекте
                    <ChevronRight class="size-5 shrink-0" />
                </Link>
            </div>
        </header>

        <HelpCard v-if="help" compact />

        <ReferralCard v-if="referral" :referral="referral" />

        <SideEffectAlertCard
            v-if="sideEffectAlert"
            :symptoms="sideEffectAlert.symptoms"
            :at="sideEffectAlert.at"
            :emergency="sideEffectAlert.emergency"
        />

        <!-- Не принял из-за самочувствия: предложить рассказать врачу, но не открывать форму самим. -->
        <section
            v-if="unwellSuggestion && !sideEffectAlert"
            class="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-xs"
            aria-labelledby="unwell-title"
            data-test="unwell-suggestion"
        >
            <div class="flex items-start gap-3">
                <Thermometer class="mt-0.5 size-6 shrink-0 text-primary" />
                <div class="flex flex-col gap-1">
                    <h2 id="unwell-title" class="text-xl font-semibold">
                        Плохо себя чувствуете?
                    </h2>
                    <p>
                        Расскажите врачу, что беспокоит: он увидит это в вашей
                        карточке.
                    </p>
                </div>
            </div>
            <Button
                variant="outline"
                size="lg"
                class="self-start rounded-full"
                as-child
            >
                <Link :href="reportSideEffect()">Рассказать врачу</Link>
            </Button>
        </section>

        <template v-if="setupFirst">
            <TimeSetupCard
                v-for="prescription in timeSetup"
                :key="prescription.id"
                :prescription="prescription"
            />
            <QuestionnaireCard v-if="assessments?.due" :status="assessments" />
        </template>

        <section class="flex flex-col gap-3" data-test="today">
            <h2 class="sr-only">Приёмы на сегодня</h2>

            <div
                v-if="!hasPrescriptions"
                class="flex flex-col items-center gap-3 rounded-2xl border border-dashed p-6 text-center"
                data-test="no-prescriptions"
            >
                <CalendarClock class="size-8 text-muted-foreground" />
                <!-- Терапевт препараты не назначает: их назначит психиатр. -->
                <template v-if="page.props.withTherapist">
                    <p class="font-medium">Препаратов пока нет.</p>
                    <p class="text-muted-foreground">
                        Препараты назначит психиатр, когда станет вашим врачом.
                        Тогда здесь появятся приёмы на день.
                    </p>
                </template>
                <template v-else>
                    <p class="font-medium">Врач пока не назначил препараты.</p>
                    <p class="text-muted-foreground">
                        Когда врач их назначит, здесь появятся приёмы на день.
                    </p>
                </template>
            </div>
            <p
                v-else-if="today.groups.length === 0"
                class="rounded-2xl border border-dashed p-4 text-muted-foreground"
            >
                На сегодня приёмов по расписанию нет.
            </p>

            <DoseGroupCard
                v-for="group in today.groups"
                :key="group.time"
                :group="group"
                day="today"
                :highlighted="isHighlighted(group)"
                :mood-enabled="moodEnabled"
                @mark="openMark"
            />
        </section>

        <!--
            Пока сейчас нечего принимать, настройка времени и опросники — над расписанием,
            иначе — после.
        -->
        <template v-if="!setupFirst">
            <TimeSetupCard
                v-for="prescription in timeSetup"
                :key="prescription.id"
                :prescription="prescription"
            />
            <QuestionnaireCard v-if="assessments?.due" :status="assessments" />
        </template>

        <section
            v-if="yesterday.groups.length"
            class="flex flex-col gap-3"
            data-test="yesterday"
        >
            <div class="flex flex-col gap-1">
                <h2 class="text-xl font-semibold">Вчера</h2>
                <p v-if="yesterdayUnmarked" class="text-muted-foreground">
                    Остались приёмы без отметки. Если вы принимали препарат,
                    отметьте это.
                </p>
            </div>

            <DoseGroupCard
                v-for="group in yesterday.groups"
                :key="group.time"
                :group="group"
                day="yesterday"
                :highlighted="isHighlighted(group)"
                @mark="openMark"
            />
        </section>

        <section
            v-if="asNeeded.length"
            class="flex flex-col gap-3"
            data-test="as-needed-list"
        >
            <div class="flex flex-col gap-1">
                <h2 class="text-xl font-semibold">По необходимости</h2>
                <p class="text-muted-foreground">
                    Отметьте, когда примете препарат.
                </p>
            </div>

            <AsNeededCard
                v-for="prescription in asNeeded"
                :key="prescription.id"
                :prescription="prescription"
            />
        </section>

        <InstallAppBanner />

        <PushBanner />

        <!-- В дни без срока — ссылка в «Самочувствие». -->
        <QuestionnaireCard
            v-if="assessments && !assessments.due"
            :status="assessments"
        />

        <section
            v-if="doctor"
            class="flex items-start gap-4 rounded-2xl border bg-card p-4 shadow-xs"
            data-test="current-doctor"
        >
            <div
                class="flex size-11 shrink-0 items-center justify-center rounded-full bg-secondary text-secondary-foreground"
            >
                <Stethoscope class="size-6" />
            </div>
            <div class="flex min-w-0 flex-1 flex-col items-start">
                <h2 class="text-muted-foreground">Ваш врач</h2>
                <p class="font-semibold break-words">{{ doctor.name }}</p>
                <p v-if="doctor.specialty" class="text-muted-foreground">
                    {{ doctor.specialty }}
                </p>
                <!-- Терапевт только отбирает и направляет: чата с ним нет. -->
                <Button
                    v-if="!page.props.withTherapist"
                    size="lg"
                    variant="ghost"
                    class="-ml-3 px-3 text-primary"
                    as-child
                >
                    <Link :href="chat()" data-test="write-to-doctor">
                        <MessageCircle />
                        Написать врачу
                    </Link>
                </Button>
            </div>
        </section>

        <div
            v-else
            class="flex items-start gap-4 rounded-2xl border border-dashed p-4"
            data-test="no-doctor"
        >
            <UserRoundX class="mt-0.5 size-6 shrink-0 text-muted-foreground" />
            <p>
                Вы сейчас не подключены к врачу в приложении. Попросите врача
                снова показать QR-код или прислать ссылку.
            </p>
        </div>

        <DoseMarkDialog
            v-model:dose="markedDose"
            v-model:status="markStatus"
            :reasons="skipReasons"
        />
    </div>
</template>
