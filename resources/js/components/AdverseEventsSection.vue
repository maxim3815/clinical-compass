<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus, Siren } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import AdverseEventController from '@/actions/App/Http/Controllers/Doctor/AdverseEventController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { CHIP_ICON, DANGER_CHIP } from '@/lib/chips';
import { plural } from '@/lib/plural';
import type { AdverseEvent } from '@/types';

/**
 * Нежелательные явления в карточке врача, сначала новые: что беспокоит (термин и слова пациента),
 * насколько мешает, на фоне каких курсов и какой день курса, прошло ли и сколько длилось, исход
 * из назначений. Новое врач отмечает «Просмотрено» в одно нажатие, комментарий — потом. Срочное
 * с открытым алертом разбирается решением по алерту.
 */
const props = defineProps<{
    patientId: number;
    events: AdverseEvent[];
}>();

const emit = defineEmits<{
    add: [];
    comment: [event: AdverseEvent];
}>();

const reviewing = ref<number | null>(null);

function review(event: AdverseEvent): void {
    router.post(
        AdverseEventController.review.url({
            patient: props.patientId,
            adverseEvent: event.id,
        }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (reviewing.value = event.id),
            onFinish: () => (reviewing.value = null),
            // Кнопка «Просмотрено» исчезла: следующий шаг — комментарий к этой записи.
            onSuccess: async () => {
                await nextTick();
                document
                    .querySelector<HTMLElement>(
                        `[data-adverse-comment="${event.id}"]`,
                    )
                    ?.focus();
            },
        },
    );
}

// Одной строкой: текст и <template v-if> подряд на сервере сливаются в один узел.
function course(event: AdverseEvent): string {
    if (event.medications.length === 0) {
        return 'Начало — вне текущих и прошлых курсов в приложении.';
    }

    return `На фоне: ${event.medications.map((item) => `${item.drug} — ${item.day}-й день курса`).join(', ')}.`;
}

function details(event: AdverseEvent): string {
    const parts = [event.severity, `начало ${event.started_on}`];

    parts.push(
        event.resolved === null
            ? 'не прошло'
            : `прошло ${event.resolved.on}, длилось ${event.resolved.days} ${plural(event.resolved.days, ['день', 'дня', 'дней'])}`,
    );

    return parts.join(' · ');
}

function source(event: AdverseEvent): string {
    const who = event.by_doctor
        ? `Записал ${event.by_doctor} ${event.reported_at}`
        : `Сообщил пациент ${event.reported_at}`;

    if (event.reviewed === null) {
        return `${who}.`;
    }

    const by = event.reviewed.by ? `, ${event.reviewed.by}` : '';
    const line = `${who} · просмотрено ${event.reviewed.at}${by}`;

    // Инициалы уже заканчиваются точкой.
    return line.endsWith('.') ? line : `${line}.`;
}
</script>

<template>
    <section
        id="adverse-events"
        class="flex scroll-mt-20 flex-col gap-3"
        aria-labelledby="adverse-events-title"
        data-test="adverse-events"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 id="adverse-events-title" class="text-lg font-semibold">
                    Нежелательные явления
                </h2>
                <p class="text-sm text-muted-foreground">
                    Сообщения пациента и записи со слов пациента.
                </p>
            </div>
            <Button
                variant="outline"
                size="sm"
                data-test="add-adverse-event"
                @click="emit('add')"
            >
                <Plus />
                Записать со слов пациента
            </Button>
        </div>

        <p
            v-if="events.length === 0"
            class="rounded-xl border border-dashed bg-card p-4 text-sm text-muted-foreground"
        >
            Пациент пока ни о чём не сообщал.
        </p>

        <ul
            v-else
            class="divide-y rounded-xl border bg-card text-sm shadow-xs"
            data-test="adverse-event-list"
        >
            <li
                v-for="event in events"
                :key="event.id"
                class="flex flex-col gap-1.5 p-4"
                :class="event.awaits_review ? 'bg-warning-subtle/40' : ''"
                :aria-labelledby="`adverse-event-${event.id}-title`"
                data-test="adverse-event"
            >
                <div class="flex flex-wrap items-start gap-2">
                    <h3
                        :id="`adverse-event-${event.id}-title`"
                        class="text-base font-semibold"
                    >
                        {{ event.term ?? event.name }}
                    </h3>
                    <span
                        v-if="
                            event.level === 'urgent' ||
                            event.level === 'emergency'
                        "
                        :class="DANGER_CHIP"
                        data-test="adverse-event-urgent"
                    >
                        <Siren :class="CHIP_ICON" />
                        {{
                            event.level === 'emergency'
                                ? 'Нужна помощь сейчас'
                                : 'Сообщить врачу сразу'
                        }}
                    </span>
                    <span
                        v-if="event.awaits_review"
                        class="rounded-md border border-warning-border bg-warning-subtle px-1.5 py-0.5 font-semibold text-warning"
                        >новое</span
                    >
                </div>
                <p
                    v-if="event.term !== null && event.term !== event.name"
                    class="text-muted-foreground"
                >
                    {{ `Словами пациента: «${event.name}»` }}
                </p>
                <p>{{ details(event) }}</p>
                <p>{{ course(event) }}</p>
                <p
                    v-for="outcome in event.outcomes"
                    :key="outcome"
                    class="font-medium"
                    data-test="adverse-event-outcome"
                >
                    {{ outcome }}
                </p>
                <p v-if="event.comment" class="break-words">
                    {{ `Комментарий: ${event.comment}` }}
                </p>
                <p class="text-muted-foreground">{{ source(event) }}</p>

                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <Button
                        v-if="event.awaits_review"
                        size="sm"
                        :disabled="reviewing === event.id"
                        :aria-busy="reviewing === event.id"
                        :aria-label="`Просмотрено: ${event.term ?? event.name}`"
                        data-test="review-adverse-event"
                        @click="review(event)"
                    >
                        <Spinner v-if="reviewing === event.id" />
                        Просмотрено
                    </Button>
                    <p
                        v-else-if="event.alert_open"
                        class="font-medium text-danger"
                    >
                        Разбирается решением по алерту.
                    </p>
                    <Button
                        v-else
                        variant="ghost"
                        size="sm"
                        class="text-primary"
                        :aria-label="`${event.comment ? 'Изменить комментарий' : 'Комментарий'}: ${event.term ?? event.name}`"
                        :data-adverse-comment="event.id"
                        data-test="comment-adverse-event"
                        @click="emit('comment', event)"
                    >
                        {{
                            event.comment
                                ? 'Изменить комментарий'
                                : 'Комментарий'
                        }}
                    </Button>
                </div>
            </li>
        </ul>
    </section>
</template>
