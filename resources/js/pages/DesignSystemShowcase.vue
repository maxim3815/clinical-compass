<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    ClinicalButton,
    ClinicalBadge,
    ClinicalSegmentedSwitch,
    ClinicalInput,
    ClinicalSearch,
    ClinicalPinInput,
    ClinicalDosageStepper,
    ClinicalAdherenceGauge,
    ClinicalStreakCard,
    ClinicalScheduledMedCard,
    ClinicalAsNeededCard,
    ClinicalMultiDoseCard,
    ClinicalPatientHeaderCard,
    ClinicalTreatmentCard,
    ClinicalSupportCard,
    ClinicalChatDialogue,
    ClinicalChatInput,
    ClinicalPsychometricCard,
    ClinicalTrendChart,
    ClinicalCriticalAlert,
    ClinicalEmergencyBanner,
    ClinicalSafetyWidget,
    ClinicalOverrideBox,
    ClinicalAdverseEventsModule,
    ClinicalFastConnectCard,
    ClinicalAdherenceHeatmap,
    ClinicalTitrationSchedule,
    ClinicalNlpExtractionCard,
    ClinicalDiscontinueModal,
    ClinicalEmptyState,
    ClinicalSkeleton,
    ClinicalMobileDock,
    ClinicalSkipReasonModal,
    ClinicalBanners,
} from '@/components/clinical';

// Interactive state for testing
const searchVal = ref('');
const textVal = ref('Aviandr (Maritupirdine)');
const focusedVal = ref('20 mg');
const pinVal = ref('8419');
const stepperVal = ref(1);
const timeSwitch = ref('morning');
const chatVal = ref('Здравствуйте, доктор! Побочных эффектов нет.');
const psychometricVal = ref<number | null>(2);
const overrideText = ref(
    'Терапия продолжается под контролем СКФ каждые 2 недели.',
);
const prnTaken = ref(1);
const activeMobileTab = ref('home');
const showSkipModal = ref(true);
const showDiscontinueModal = ref(true);

const multiMedList = ref([
    { id: 1, name: 'Авиандр', dosage: '20 мг • 1 таб' },
    { id: 2, name: 'Эсциталопрам', dosage: '10 мг • 1 таб' },
]);
</script>

<template>
    <Head title="Clinical Compass — Design System Showcase" />

    <div
        class="min-h-screen bg-[#F8FAF9] pb-24 font-sans text-[#1E293B] antialiased"
    >
        <!-- Header -->
        <header
            class="sticky top-0 z-40 border-b border-[#E2E8F0] bg-white px-6 py-4 shadow-xs"
        >
            <div class="mx-auto flex max-w-7xl items-center justify-between">
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-[#235347] text-lg font-bold text-white"
                    >
                        🧭
                    </div>
                    <div>
                        <h1 class="text-lg font-bold text-[#1E293B]">
                            Clinical Compass
                        </h1>
                        <p class="text-xs text-[#64748B]">
                            Дизайн-система и библиотека компонентов
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <span
                        class="rounded-full border border-[#BADBCC] bg-[#E8F2EC] px-3 py-1 text-xs font-semibold text-[#235347]"
                    >
                        8 Листов UI Kit • 97 элементов
                    </span>
                </div>
            </div>
        </header>

        <main class="mx-auto flex max-w-7xl flex-col gap-12 px-6 py-8">
            <!-- Section 1: Color Tokens -->
            <section class="flex flex-col gap-4">
                <div class="border-b border-[#E2E8F0] pb-2">
                    <h2 class="text-xl font-bold text-[#1E293B]">
                        1. Палитра дизайн-токенов
                    </h2>
                    <p class="text-xs text-[#64748B]">
                        Soft anti-anxiety psychiatric UI & Swiss clinical tech
                    </p>
                </div>

                <div
                    class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-6"
                >
                    <div
                        class="flex h-24 flex-col justify-between rounded-2xl bg-[#235347] p-3.5 text-white shadow-xs"
                    >
                        <span class="text-xs font-bold">Deep Pine</span>
                        <span class="text-[11px] opacity-80">#235347</span>
                    </div>
                    <div
                        class="flex h-24 flex-col justify-between rounded-2xl border border-[#CDE3D5] bg-[#E8F2EC] p-3.5 text-[#235347] shadow-xs"
                    >
                        <span class="text-xs font-bold">Calming Mint</span>
                        <span class="text-[11px] opacity-80">#E8F2EC</span>
                    </div>
                    <div
                        class="flex h-24 flex-col justify-between rounded-2xl border border-[#EBE7DF] bg-[#FBF9F5] p-3.5 text-[#1E293B] shadow-xs"
                    >
                        <span class="text-xs font-bold">Warm Off-White</span>
                        <span class="text-[11px] opacity-80">#FBF9F5</span>
                    </div>
                    <div
                        class="flex h-24 flex-col justify-between rounded-2xl bg-[#1E293B] p-3.5 text-white shadow-xs"
                    >
                        <span class="text-xs font-bold">Pure Slate</span>
                        <span class="text-[11px] opacity-80">#1E293B</span>
                    </div>
                    <div
                        class="flex h-24 flex-col justify-between rounded-2xl border border-[#FCD34D] bg-[#FEF3C7] p-3.5 text-[#B45309] shadow-xs"
                    >
                        <span class="text-xs font-bold">Warning Amber</span>
                        <span class="text-[11px] opacity-80">#F59E0B</span>
                    </div>
                    <div
                        class="flex h-24 flex-col justify-between rounded-2xl border border-[#FCA5A5] bg-[#FEE2E2] p-3.5 text-[#B91C1C] shadow-xs"
                    >
                        <span class="text-xs font-bold">Alert Coral</span>
                        <span class="text-[11px] opacity-80">#EF4444</span>
                    </div>
                </div>
            </section>

            <!-- Section 2: Buttons & Controls -->
            <section class="flex flex-col gap-4">
                <div class="border-b border-[#E2E8F0] pb-2">
                    <h2 class="text-xl font-bold text-[#1E293B]">
                        2. Кнопки и контролы
                    </h2>
                    <p class="text-xs text-[#64748B]">
                        Варианты, размеры и интерактивные состояния
                    </p>
                </div>

                <div
                    class="flex flex-col gap-6 rounded-3xl border border-[#E2E8F0] bg-white p-6 shadow-xs"
                >
                    <div>
                        <span
                            class="mb-3 block text-xs font-bold tracking-wider text-[#64748B] uppercase"
                        >
                            Pill Actions
                        </span>
                        <div class="flex flex-wrap items-center gap-3">
                            <ClinicalButton variant="primary"
                                >✓ Taken (Принял)</ClinicalButton
                            >
                            <ClinicalButton variant="secondary"
                                >Skip (Пропустить)</ClinicalButton
                            >
                            <ClinicalButton variant="outline"
                                >View full profile →</ClinicalButton
                            >
                            <ClinicalButton variant="warning"
                                >View recommendations</ClinicalButton
                            >
                            <ClinicalButton variant="critical"
                                >Emergency Call</ClinicalButton
                            >
                            <ClinicalButton variant="pending"
                                >🕒 Not due yet</ClinicalButton
                            >
                            <ClinicalButton variant="primary" disabled
                                >Disabled State</ClinicalButton
                            >
                            <ClinicalButton variant="primary" loading
                                >Loading</ClinicalButton
                            >
                        </div>
                    </div>

                    <div class="border-t border-[#F1F5F9] pt-4">
                        <span
                            class="mb-3 block text-xs font-bold tracking-wider text-[#64748B] uppercase"
                        >
                            Segmented Switch & Icon Controls
                        </span>
                        <div class="flex flex-wrap items-center gap-6">
                            <ClinicalSegmentedSwitch
                                v-model="timeSwitch"
                                :options="[
                                    {
                                        value: 'morning',
                                        label: 'Morning',
                                        sublabel: '8:00 AM',
                                        icon: '☀️',
                                    },
                                    {
                                        value: 'evening',
                                        label: 'Evening',
                                        sublabel: '8:00 PM',
                                        icon: '🌙',
                                    },
                                ]"
                            />

                            <div class="flex items-center gap-2">
                                <ClinicalButton
                                    variant="primary"
                                    shape="circle"
                                    size="md"
                                >
                                    <span class="text-base">✈️</span>
                                </ClinicalButton>
                                <ClinicalButton
                                    variant="secondary"
                                    shape="circle"
                                    size="md"
                                >
                                    <span class="text-base">📎</span>
                                </ClinicalButton>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Section 3: Form Inputs & Fields -->
            <section class="flex flex-col gap-4">
                <div class="border-b border-[#E2E8F0] pb-2">
                    <h2 class="text-xl font-bold text-[#1E293B]">
                        3. Поля ввода и формы
                    </h2>
                    <p class="text-xs text-[#64748B]">
                        Сквозной поиск, текстовые поля, PIN-код и степпер
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div
                        class="flex flex-col gap-4 rounded-3xl border border-[#E2E8F0] bg-white p-6 shadow-xs"
                    >
                        <ClinicalSearch v-model="searchVal" />
                        <ClinicalInput
                            v-model="textVal"
                            label="Medication Name"
                            placeholder="e.g. Aviandr"
                            hint="Введите торговое или международное наименование"
                        />
                        <ClinicalInput
                            v-model="focusedVal"
                            label="Daily Dosage"
                            placeholder="e.g. 20 mg"
                        />
                        <ClinicalInput
                            model-value="Wrong format"
                            label="Error state test"
                            error="Некорректная дозировка для данного препарата"
                        />
                    </div>

                    <div
                        class="flex flex-col justify-between gap-6 rounded-3xl border border-[#E2E8F0] bg-white p-6 shadow-xs"
                    >
                        <div>
                            <span
                                class="mb-2 block text-xs font-bold text-[#64748B]"
                            >
                                4-Digit PIN Code (Быстрое подключение на
                                приёме):
                            </span>
                            <ClinicalPinInput v-model="pinVal" />
                        </div>

                        <div>
                            <span
                                class="mb-2 block text-xs font-bold text-[#64748B]"
                            >
                                Dosage / Tablet Stepper:
                            </span>
                            <ClinicalDosageStepper
                                v-model="stepperVal"
                                :step="0.5"
                                unit="таб (20 мг)"
                            />
                        </div>

                        <div>
                            <span
                                class="mb-2 block text-xs font-bold text-[#64748B]"
                            >
                                Patient Chat Input:
                            </span>
                            <ClinicalChatInput v-model="chatVal" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- Section 4: Badges & Progress -->
            <section class="flex flex-col gap-4">
                <div class="border-b border-[#E2E8F0] pb-2">
                    <h2 class="text-xl font-bold text-[#1E293B]">
                        4. Бейджи, метрики и прогресс
                    </h2>
                    <p class="text-xs text-[#64748B]">
                        Статусы приёма, шкала комплаентности и стрик
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <div
                        class="flex flex-col gap-4 rounded-3xl border border-[#E2E8F0] bg-white p-6 shadow-xs"
                    >
                        <span
                            class="block text-xs font-bold tracking-wider text-[#64748B] uppercase"
                        >
                            Status Badges
                        </span>
                        <div class="flex flex-wrap gap-2">
                            <ClinicalBadge variant="taken"
                                >✓ Taken</ClinicalBadge
                            >
                            <ClinicalBadge variant="below-target"
                                >⚠️ 66% Below target</ClinicalBadge
                            >
                            <ClinicalBadge variant="missed"
                                >✕ Missed</ClinicalBadge
                            >
                            <ClinicalBadge variant="skipped"
                                >⊘ Skipped</ClinicalBadge
                            >
                            <ClinicalBadge variant="referral-mandatory"
                                >Направление: ОБЯЗАТЕЛЬНО</ClinicalBadge
                            >
                            <ClinicalBadge variant="referral-recommended"
                                >Консультация: ЖЕЛАТЕЛЬНО</ClinicalBadge
                            >
                            <ClinicalBadge variant="condition" removable
                                >Anxiety</ClinicalBadge
                            >
                            <ClinicalBadge variant="condition" removable
                                >Depression</ClinicalBadge
                            >
                        </div>
                    </div>

                    <ClinicalAdherenceGauge :percentage="66" :target="80" />

                    <ClinicalStreakCard :streak-days="7" />
                </div>
            </section>

            <!-- Section 5: Patient PWA Modules -->
            <section class="flex flex-col gap-4">
                <div class="border-b border-[#E2E8F0] pb-2">
                    <h2 class="text-xl font-bold text-[#1E293B]">
                        5. Мобильные модули пациента (PWA)
                    </h2>
                    <p class="text-xs text-[#64748B]">
                        Карточки приёма в 1 тап, препараты по необходимости и
                        баннеры
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <ClinicalScheduledMedCard
                        medication-name="Aviandr 20mg"
                        dosage-text="1 tablet"
                        category-text="Antidepressant"
                        time-of-day="Morning"
                        scheduled-time="8:00 AM"
                        status="scheduled"
                    />

                    <ClinicalAsNeededCard
                        medication-name="Гидроксизин 25 мг"
                        dosage-text="1 таблетка при тревоге"
                        :taken-today="prnTaken"
                        :daily-limit="2"
                        @take="prnTaken++"
                    />

                    <ClinicalMultiDoseCard :medications="multiMedList" />
                </div>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <ClinicalSupportCard />
                    <ClinicalBanners type="push" />
                </div>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <ClinicalBanners type="pwa" />
                    <div class="flex flex-col items-center justify-center p-4">
                        <span class="mb-2 text-xs font-semibold text-[#64748B]"
                            >Mobile Bottom Dock:</span
                        >
                        <ClinicalMobileDock
                            :active-tab="activeMobileTab"
                            @change="activeMobileTab = $event"
                        />
                    </div>
                </div>

                <!-- Skip Reason Bottom Sheet Component Preview -->
                <div class="flex justify-center p-4">
                    <ClinicalSkipReasonModal :is-open="showSkipModal" />
                </div>
            </section>

            <!-- Section 6: Doctor CDSS Dashboard Modules -->
            <section class="flex flex-col gap-4">
                <div class="border-b border-[#E2E8F0] pb-2">
                    <h2 class="text-xl font-bold text-[#1E293B]">
                        6. Клинический кабинет врача (CDSS)
                    </h2>
                    <p class="text-xs text-[#64748B]">
                        Карточка пациента, терапия и диалог
                    </p>
                </div>

                <ClinicalPatientHeaderCard
                    name="Alexandra Petrova"
                    patient-id="CP-2847"
                    :age="34"
                    gender="Female"
                    :conditions="['Anxiety', 'Depression']"
                />

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <ClinicalTreatmentCard
                        medication-name="Aviandr (Maritupirdine)"
                        dosage-text="20 mg daily (Morning & Evening)"
                    />

                    <ClinicalChatDialogue />
                </div>
            </section>

            <!-- Section 7: Psychometrics & Critical Alerts -->
            <section class="flex flex-col gap-4">
                <div class="border-b border-[#E2E8F0] pb-2">
                    <h2 class="text-xl font-bold text-[#1E293B]">
                        7. Психометрические шкалы и экстренные алерты
                    </h2>
                    <p class="text-xs text-[#64748B]">
                        Опросники GAD-7 / PHQ-9, динамика редукции и кризисная
                        поддержка
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <ClinicalPsychometricCard
                        v-model="psychometricVal"
                        question="Feeling nervous, anxious, or on edge over the past 2 weeks?"
                    />

                    <ClinicalTrendChart />
                </div>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <ClinicalCriticalAlert />
                    <ClinicalEmergencyBanner />
                </div>
            </section>

            <!-- Section 8: CDSS Safety, Adverse Events & Advanced Tools -->
            <section class="flex flex-col gap-4">
                <div class="border-b border-[#E2E8F0] pb-2">
                    <h2 class="text-xl font-bold text-[#1E293B]">
                        8. Безопасность, побочные эффекты и СППВР
                    </h2>
                    <p class="text-xs text-[#64748B]">
                        Светофор противопоказаний, матрица 28 дней, титрация и
                        выписки
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <ClinicalSafetyWidget
                        status="safe"
                        title="Безопасно: 1-я линия терапии"
                        details="Препарат разрешен, противопоказания не выявлены."
                    />
                    <ClinicalSafetyWidget
                        status="caution"
                        title="С осторожностью: Межлекарственное взаимодействие"
                        details="Параллельный приём с Эсциталопрамом требует контроля QT-интервала."
                    />
                    <ClinicalSafetyWidget
                        status="contraindicated"
                        title="Противопоказано: СКФ 52 мл/мин"
                        details="Инструкция Авиандра запрещает приём при тяжелой ХБП."
                        guideline-quote="Противопоказан при почечной недостаточности с клиренсом креатинина < 60 мл/мин"
                    />
                </div>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <ClinicalOverrideBox v-model="overrideText" />
                    <ClinicalAdverseEventsModule />
                </div>

                <ClinicalFastConnectCard />

                <ClinicalAdherenceHeatmap />

                <ClinicalTitrationSchedule />

                <ClinicalNlpExtractionCard />

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <ClinicalDiscontinueModal :is-open="showDiscontinueModal" />
                    <div class="flex flex-col gap-6">
                        <ClinicalEmptyState />
                        <ClinicalSkeleton />
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
