<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CircleCheck,
    MessageCircle,
    OctagonAlert,
    Siren,
    TriangleAlert,
} from '@lucide/vue';
import AdherenceSignal from '@/components/AdherenceSignal.vue';
import { alertTitle } from '@/lib/alerts';
import { CHIP_ICON, DANGER_CHIP, MESSAGE_CHIP } from '@/lib/chips';
import { formatAge, plural } from '@/lib/plural';
import { show } from '@/routes/doctor/patients';
import { show as chat } from '@/routes/doctor/patients/chat';
import type { AttentionAlert, AttentionPatient } from '@/types';

defineProps<{
    patients: AttentionPatient[];
}>();

// Алертов у пациента обычно один: виден новый, остальные — числом.
function alertLabel(alerts: AttentionAlert[]): string {
    const [latest] = alerts;
    const more = alerts.length > 1 ? ` · ещё ${alerts.length - 1}` : '';

    return `${alertTitle(latest.rule, latest.trigger)} · ${latest.acknowledged ? 'ждёт решения' : 'не принят'}${more}`;
}

function messages(count: number): string {
    return `${count} ${plural(count, ['новое сообщение', 'новых сообщения', 'новых сообщений'])}`;
}
</script>

<template>
    <section
        class="flex flex-col gap-3"
        aria-labelledby="attention-title"
        data-test="attention"
    >
        <h2 id="attention-title" class="text-lg font-semibold">
            Требуют внимания
            <span
                v-if="patients.length > 0"
                class="font-normal text-muted-foreground"
                >· {{ patients.length }}</span
            >
        </h2>

        <p
            v-if="patients.length > 0"
            class="-mt-2 text-sm text-muted-foreground"
        >
            Число у «Пациентов» в меню показывает пациентов с алертом,
            противопоказанием без решения или новыми пропусками.
        </p>

        <p
            v-if="patients.length === 0"
            class="flex items-center gap-2 rounded-xl border bg-card p-4 text-muted-foreground"
            data-test="attention-empty"
        >
            <CircleCheck class="size-5 shrink-0 text-success" />
            Сейчас никто не требует внимания: нет алертов, сигналов о приёме,
            противопоказаний без решения и новых сообщений.
        </p>

        <ul v-else class="divide-y rounded-xl border bg-card shadow-xs">
            <li
                v-for="patient in patients"
                :key="patient.id"
                class="relative flex flex-col gap-2 p-4 transition-colors hover:bg-accent/50 has-[a:focus-visible]:bg-accent/50 @2xl:flex-row @2xl:items-start @2xl:justify-between @2xl:gap-6"
                data-test="attention-row"
            >
                <div class="flex min-w-0 flex-col gap-0.5">
                    <!-- Ссылка растянута на всю строку: карточка открывается щелчком в любом месте. -->
                    <Link
                        :href="show(patient.id)"
                        class="font-semibold underline-offset-4 after:absolute after:inset-0 hover:underline"
                    >
                        {{ patient.name }}
                    </Link>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="patient.age !== null"
                            >{{ formatAge(patient.age) }} ·
                        </template>
                        <template v-if="patient.diagnosis"
                            >{{ patient.diagnosis.code }}
                            {{ patient.diagnosis.name }}</template
                        >
                        <template v-else>основной диагноз не указан</template>
                    </p>
                </div>

                <ul
                    class="flex flex-wrap items-center gap-2 text-sm @2xl:max-w-[60%] @2xl:justify-end"
                    aria-label="Причины"
                >
                    <li
                        v-if="patient.alerts.length > 0"
                        :class="DANGER_CHIP"
                        data-test="attention-alert"
                    >
                        <Siren :class="CHIP_ICON" />
                        <span>{{ alertLabel(patient.alerts) }}</span>
                    </li>
                    <li
                        v-if="patient.contraindications.length > 0"
                        :class="DANGER_CHIP"
                        data-test="attention-contraindication"
                    >
                        <OctagonAlert :class="CHIP_ICON" />
                        <span
                            >Противопоказание без решения:
                            {{ patient.contraindications.join(', ') }}</span
                        >
                    </li>
                    <li
                        v-if="patient.adherence"
                        class="flex items-start gap-1.5"
                        data-test="attention-adherence"
                    >
                        <TriangleAlert
                            class="mt-1 size-4 shrink-0 text-warning"
                            aria-hidden="true"
                        />
                        <span class="flex flex-wrap items-center gap-1.5">
                            <AdherenceSignal :signal="patient.adherence" card />
                            <span
                                v-if="patient.new_misses"
                                class="rounded-md border border-warning-border bg-warning-subtle px-1.5 py-0.5 font-semibold text-warning"
                                >новые пропуски</span
                            >
                        </span>
                    </li>
                    <li v-if="patient.unread > 0">
                        <Link
                            :href="chat(patient.id)"
                            class="relative z-10 pointer-coarse:min-h-11 pointer-coarse:px-3"
                            :class="MESSAGE_CHIP"
                            :aria-label="`Сообщения: ${messages(patient.unread)}, ${patient.name}`"
                            data-test="attention-unread"
                        >
                            <MessageCircle class="size-4 shrink-0" />
                            {{ messages(patient.unread) }}
                        </Link>
                    </li>
                </ul>
            </li>
        </ul>
    </section>
</template>
