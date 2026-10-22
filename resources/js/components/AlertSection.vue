<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link, router } from '@inertiajs/vue3';
import { ExternalLink, MessageCircle, Siren } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import AlertController from '@/actions/App/Http/Controllers/Doctor/AlertController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { alertTitle } from '@/lib/alerts';
import { CHIP_ICON, DANGER_CHIP } from '@/lib/chips';
import type { PatientAlert } from '@/types';

/**
 * Алерты в карточке пациента: открытые — сверху, с «Принял» и «Закрыть с решением»,
 * ниже история закрытых: что сработало, через сколько показан, кто принял и чем закрыл.
 */
const props = defineProps<{
    patientId: number;
    alerts: PatientAlert[];
    chatHref: InertiaLinkProps['href'];
}>();

const emit = defineEmits<{
    close: [alert: PatientAlert];
}>();

const open = computed(() => props.alerts.filter((alert) => !alert.closed));
const closed = computed(() => props.alerts.filter((alert) => alert.closed));
const accepting = ref<number | null>(null);

function accept(alert: PatientAlert): void {
    router.post(
        AlertController.acknowledge.url({
            patient: props.patientId,
            alert: alert.id,
        }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (accepting.value = alert.id),
            onFinish: () => (accepting.value = null),
            // Кнопка «Принял» исчезла: следующий шаг — решение.
            onSuccess: async () => {
                await nextTick();
                document
                    .querySelector<HTMLElement>(
                        `[data-alert-close="${alert.id}"]`,
                    )
                    ?.focus();
            },
        },
    );
}

// Одной строкой: текст и <template v-if> подряд на сервере сливаются в один узел.
function delivery(alert: PatientAlert): string {
    const push = alert.pushed ? ' Пуш врачу отправлен.' : '';

    if (alert.shown === null) {
        return `Ответ ${alert.submitted_at}. На экране врача ещё не показан.${push}`;
    }

    const how = alert.shown.realtime
        ? 'в открытом кабинете'
        : 'когда открыли кабинет';

    return `Ответ ${alert.submitted_at}. Показан ${how} через ${alert.shown.after}.${push}`;
}

// Кто принял и закрыл — обычно один врач: ФИО один раз в конце.
function history(alert: PatientAlert): string {
    const parts = [`Ответ ${alert.submitted_at}`];
    const by = new Set(
        [alert.acknowledged?.by, alert.closed?.by].filter(
            (name): name is string => Boolean(name),
        ),
    );

    if (alert.shown) {
        parts.push(`показан через ${alert.shown.after}`);
    }

    if (alert.acknowledged) {
        parts.push(
            by.size > 1
                ? `принят ${alert.acknowledged.at}, ${alert.acknowledged.by}`
                : `принят ${alert.acknowledged.at}`,
        );
    }

    if (alert.closed) {
        parts.push(
            by.size > 1
                ? `закрыт ${alert.closed.at}, ${alert.closed.by}`
                : `закрыт ${alert.closed.at}`,
        );
    }

    if (by.size === 1) {
        parts.push([...by][0]);
    }

    const line = parts.join(' · ');

    // Инициалы уже заканчиваются точкой.
    return line.endsWith('.') ? line : `${line}.`;
}
</script>

<template>
    <section
        id="alerts"
        class="flex scroll-mt-20 flex-col gap-3"
        aria-labelledby="alerts-title"
        data-test="alerts"
    >
        <h2
            id="alerts-title"
            class="text-lg font-semibold outline-none"
            tabindex="-1"
        >
            {{ open.length === 1 ? 'Алерт' : 'Алерты' }}
        </h2>

        <article
            v-for="alert in open"
            :key="alert.id"
            class="flex flex-col gap-3 rounded-xl border-2 border-danger bg-card p-4 shadow-xs"
            :aria-labelledby="`alert-${alert.id}-title`"
            data-test="open-alert"
        >
            <div class="flex flex-wrap items-start justify-between gap-2">
                <h3
                    :id="`alert-${alert.id}-title`"
                    class="flex items-start gap-2 font-semibold text-danger"
                >
                    <Siren class="mt-0.5 size-5 shrink-0" />
                    {{ alertTitle(alert.rule, alert.trigger) }}
                </h3>
                <span
                    v-if="alert.acknowledged"
                    class="rounded-md border px-2 py-1 text-sm font-medium text-muted-foreground"
                    data-test="alert-status"
                    >{{
                        `Принят ${alert.acknowledged.at}${alert.acknowledged.by ? `, ${alert.acknowledged.by}` : ''} · ждёт решения`
                    }}</span
                >
                <span
                    v-else
                    class="text-sm"
                    :class="DANGER_CHIP"
                    data-test="alert-status"
                    ><Siren :class="CHIP_ICON" />Не принят</span
                >
            </div>

            <ul class="flex flex-col gap-1">
                <li v-for="answer in alert.answers" :key="answer">
                    {{ answer }}
                </li>
            </ul>
            <p class="text-sm">{{ alert.text }}</p>
            <p class="text-sm text-muted-foreground" data-test="alert-delivery">
                {{ delivery(alert) }}
            </p>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="!alert.acknowledged"
                    variant="outline"
                    :disabled="accepting === alert.id"
                    :aria-busy="accepting === alert.id"
                    :aria-describedby="`alert-${alert.id}-title`"
                    data-test="alert-accept"
                    @click="accept(alert)"
                >
                    <Spinner v-if="accepting === alert.id" />
                    Принял
                </Button>
                <Button
                    :data-alert-close="alert.id"
                    :aria-describedby="`alert-${alert.id}-title`"
                    data-test="alert-close"
                    @click="emit('close', alert)"
                >
                    Закрыть с решением
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="chatHref" data-test="alert-chat">
                        <MessageCircle />
                        Написать
                    </Link>
                </Button>
            </div>

            <details class="text-sm text-muted-foreground">
                <summary
                    class="w-fit cursor-pointer rounded-sm hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid pointer-coarse:py-2.5"
                >
                    Источник правила
                </summary>
                <div class="mt-2 flex flex-col gap-1">
                    <p>
                        {{
                            `${alert.source.document}, ${alert.source.section}`
                        }}
                    </p>
                    <p v-if="alert.source.quote" lang="en">
                        «{{ alert.source.quote }}»
                    </p>
                    <a
                        :href="alert.source.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 self-start text-primary underline underline-offset-4 pointer-coarse:min-h-11"
                    >
                        Открыть документ
                        <span class="sr-only">(в новой вкладке)</span>
                        <ExternalLink class="size-4" />
                    </a>
                </div>
            </details>
        </article>

        <template v-if="closed.length > 0">
            <h3
                v-if="open.length > 0"
                class="mt-2 font-semibold text-muted-foreground"
            >
                Закрытые алерты
            </h3>
            <ul
                class="divide-y rounded-xl border bg-card text-sm shadow-xs"
                data-test="closed-alerts"
            >
                <li
                    v-for="alert in closed"
                    :key="alert.id"
                    class="flex flex-col gap-1 p-4"
                >
                    <p class="font-medium">
                        {{ alertTitle(alert.rule, alert.trigger) }}
                    </p>
                    <p>
                        <span class="font-semibold">{{
                            alert.closed!.decision
                        }}</span
                        >{{
                            alert.closed!.comment
                                ? `. ${alert.closed!.comment}`
                                : ''
                        }}
                    </p>
                    <p class="text-muted-foreground">{{ history(alert) }}</p>
                </li>
            </ul>
        </template>
    </section>
</template>
