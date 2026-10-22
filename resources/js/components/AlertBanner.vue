<script setup lang="ts">
import { http, Link, router, usePage } from '@inertiajs/vue3';
import { Siren } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import AlertController from '@/actions/App/Http/Controllers/Doctor/AlertController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useRealtime } from '@/composables/useRealtime';
import { alertTitle } from '@/lib/alerts';
import { playAlertSound, unlockAlertSound } from '@/lib/alertSound';
import { show } from '@/routes/doctor/patients';
import type { UrgentAlert } from '@/types';

/**
 * Красная плашка непринятых алертов на всех страницах кабинета врача. Новый алерт приходит
 * событием без перезагрузки, со звуком. Когда плашку видно, браузер сообщает серверу
 * «показан»: от ответа пациента до этой отметки считается время доставки. «Принял» убирает
 * плашку, алерт остаётся в карточке пациента до решения.
 */
const page = usePage();
const alerts = computed(() => page.props.urgentAlerts);

// В карточке пациента его алерт — первым блоком страницы, в плашке он бы повторился.
const cardPatientId = computed(() =>
    page.component === 'doctor/patients/Show'
        ? (page.props.patient as { id: number }).id
        : null,
);
const visible = computed(() =>
    alerts.value.filter((alert) => alert.patient_id !== cardPatientId.value),
);

// Алерты, пришедшие событием в эту вкладку: кабинет был открыт.
const realtime = new Set<number>();
// Отметка «показан» уже ушла из этой вкладки.
const reported = new Set<number>();
// Звук уже прозвучал в этой вкладке.
const heard = new Set<number>();
const accepting = ref<number | null>(null);
// Скринридер читает только новый алерт, а не всю плашку с кнопками.
const announcement = ref('');
const banner = useTemplateRef<HTMLElement>('banner');

useRealtime<{ alerts: UrgentAlert[]; count: number }>(
    `App.Models.User.${page.props.auth.user.id}`,
    'alerts.changed',
    (event) => {
        const fresh = event.alerts.filter(
            (alert) => !alerts.value.some((known) => known.id === alert.id),
        );

        fresh.forEach((alert) => realtime.add(alert.id));

        if (fresh.length > 0) {
            announcement.value = fresh
                .map(
                    (alert) =>
                        `Срочно: ${alert.patient}. ${alertTitle(alert.rule, alert.trigger)}.`,
                )
                .join(' ');
        }

        // Одним обновлением: два replaceProp подряд перетирают друг друга.
        router.replace({
            preserveScroll: true,
            preserveState: true,
            props: (props) => ({
                ...props,
                urgentAlerts: event.alerts,
                patientSignals: event.count,
            }),
        });
    },
);

// Алерт на экране и в карточке пациента: он показан, даже если плашка его не повторяет.
function reportShown(): void {
    if (document.visibilityState !== 'visible') {
        return;
    }

    for (const alert of alerts.value) {
        if (alert.shown || reported.has(alert.id)) {
            continue;
        }

        reported.add(alert.id);
        http.getClient()
            .request({
                ...AlertController.shown({
                    patient: alert.patient_id,
                    alert: alert.id,
                }),
                data: { via: realtime.has(alert.id) ? 'realtime' : 'page' },
            })
            .catch(() => reported.delete(alert.id));
    }
}

// Звук — на алерт, которого врач ещё не видел: пришёл событием или впервые на экране.
function ring(): void {
    const fresh = alerts.value.filter(
        (alert) =>
            !heard.has(alert.id) && (!alert.shown || realtime.has(alert.id)),
    );

    if (fresh.length > 0) {
        fresh.forEach((alert) => heard.add(alert.id));
        playAlertSound();
    }
}

// Высота плашки: на неё прокрутка к разделу и к полю в фокусе отступает от верха (app.css).
let resizeObserver: ResizeObserver | null = null;

function setHeight(): void {
    document.documentElement.style.setProperty(
        '--alert-banner-height',
        `${banner.value?.offsetHeight ?? 0}px`,
    );
}

function trackHeight(): void {
    resizeObserver?.disconnect();
    setHeight();

    if (banner.value) {
        resizeObserver = new ResizeObserver(setHeight);
        resizeObserver.observe(banner.value);
    }
}

watch(alerts, () => {
    ring();
    reportShown();
});

watch(
    () => visible.value.length > 0,
    () => void nextTick(trackHeight),
);

onMounted(() => {
    ring();
    reportShown();
    trackHeight();
    document.addEventListener('visibilitychange', reportShown);
    window.addEventListener('pointerdown', unlockAlertSound);
    window.addEventListener('keydown', unlockAlertSound);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    document.documentElement.style.removeProperty('--alert-banner-height');
    document.removeEventListener('visibilitychange', reportShown);
    window.removeEventListener('pointerdown', unlockAlertSound);
    window.removeEventListener('keydown', unlockAlertSound);
});

function accept(alert: UrgentAlert): void {
    router.post(
        AlertController.acknowledge.url({
            patient: alert.patient_id,
            alert: alert.id,
        }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (accepting.value = alert.id),
            onFinish: () => (accepting.value = null),
            // Строка исчезла вместе с кнопкой: фокус — на следующий алерт или на страницу.
            onSuccess: async () => {
                await nextTick();
                (
                    banner.value?.querySelector<HTMLElement>(
                        '[data-test="urgent-alert-accept"]',
                    ) ?? document.getElementById('content')
                )?.focus();
            },
        },
    );
}
</script>

<template>
    <p class="sr-only" aria-live="assertive" aria-atomic="true">
        {{ announcement }}
    </p>
    <section
        v-if="visible.length > 0"
        ref="banner"
        class="max-h-[40dvh] overflow-y-auto border-b-2 border-danger bg-danger-subtle"
        aria-label="Срочные алерты"
        data-test="alert-banner"
    >
        <ul class="divide-y divide-danger-border">
            <li
                v-for="alert in visible"
                :key="alert.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3 md:px-6"
                data-test="urgent-alert"
            >
                <div class="flex min-w-0 flex-1 basis-80 items-start gap-3">
                    <Siren class="mt-0.5 size-6 shrink-0 text-danger" />
                    <div class="flex min-w-0 flex-col">
                        <p class="font-semibold text-danger">
                            {{ `Срочно: ${alert.patient}` }}
                        </p>
                        <p class="text-sm">
                            {{
                                `${alertTitle(alert.rule, alert.trigger)}. Ответ ${alert.at}.`
                            }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" as-child>
                        <Link
                            :href="`${show.url(alert.patient_id)}#alerts`"
                            data-test="urgent-alert-open"
                            >Открыть карточку<span class="sr-only">{{
                                `: ${alert.patient}`
                            }}</span></Link
                        >
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="border-muted-foreground bg-card"
                        :disabled="accepting === alert.id"
                        :aria-busy="accepting === alert.id"
                        :aria-label="`Принял: ${alert.patient}`"
                        data-test="urgent-alert-accept"
                        @click="accept(alert)"
                    >
                        <Spinner v-if="accepting === alert.id" />
                        Принял
                    </Button>
                </div>
            </li>
        </ul>
    </section>
</template>
