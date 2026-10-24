<script setup lang="ts">
import { LifeBuoy } from '@lucide/vue';
import type { HelpEvent } from '@/types';

/**
 * Тихие строки в карточке пациента: когда он открывал экран помощи и нажимал на нём звонки,
 * не сообщая врачу. Без алерта и счётчика: об этом можно спросить на приёме.
 */
defineProps<{
    events: HelpEvent[];
    // Блок стоит сразу под алертом по «Сообщить врачу»: пояснение про алерт там лишнее.
    nearAlert?: boolean;
}>();
</script>

<template>
    <section
        id="help-events"
        class="flex scroll-mt-20 flex-col gap-3 rounded-xl border bg-card p-4 text-sm shadow-xs"
        aria-labelledby="help-events-title"
        data-test="help-events"
    >
        <div>
            <h2
                id="help-events-title"
                class="flex items-center gap-2 text-lg font-semibold"
            >
                <LifeBuoy class="size-5 shrink-0 text-muted-foreground" />
                Экран помощи
            </h2>
            <!-- Одной строкой: текст и <template v-if> подряд на сервере сливаются в один узел. -->
            <p class="text-muted-foreground">
                {{
                    nearAlert
                        ? 'Открытия экрана и звонки с него за последние 30 дней.'
                        : 'Открытия экрана и звонки с него за последние 30 дней. «Сообщить врачу» приходит алертом.'
                }}
            </p>
        </div>

        <ul class="flex flex-col gap-1.5">
            <li
                v-for="(event, index) in events"
                :key="index"
                data-test="help-event"
            >
                <span class="text-muted-foreground tabular-nums">{{
                    event.at
                }}</span>
                {{ ` · ${event.label}` }}
            </li>
        </ul>
    </section>
</template>
