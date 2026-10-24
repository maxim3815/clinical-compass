<script setup lang="ts">
import { Siren } from '@lucide/vue';
import type { FilledAssessment } from '@/types';

/**
 * Ответы заполнения на каждый вопрос: в списке заполнений карточки и на странице ответов из очереди.
 * Отмеченный вопрос (9-й PHQ-9) с ответом не «совсем нет» подсвечен не только цветом: сирена
 * и слово «сигнал» для скринридера. На телефоне ответ стоит под вопросом.
 */
defineProps<{
    assessment: FilledAssessment;
}>();
</script>

<template>
    <table class="w-full text-left text-sm">
        <caption class="pb-1 text-left font-semibold">
            {{
                `${assessment.name}: ${assessment.total} из ${assessment.max}, ${assessment.band}`
            }}
        </caption>
        <thead class="sr-only">
            <tr>
                <th scope="col">Вопрос</th>
                <th scope="col">Ответ</th>
            </tr>
        </thead>
        <tbody>
            <tr
                v-for="answer in assessment.answers"
                :key="answer.item"
                class="border-b last:border-b-0 max-sm:flex max-sm:flex-col max-sm:py-1.5"
                :class="
                    answer.highlight && answer.score > 0
                        ? 'bg-danger-subtle'
                        : ''
                "
            >
                <td class="py-1.5 pr-3 align-top max-sm:py-0">
                    <template v-if="answer.highlight && answer.score > 0">
                        <Siren
                            class="mr-1 inline size-4 align-text-bottom text-danger"
                            aria-hidden="true"
                        />
                        <span class="sr-only">Сигнал. </span>
                    </template>
                    <span class="text-muted-foreground tabular-nums"
                        >{{ answer.item }}.</span
                    >
                    {{ answer.text }}
                    <span
                        v-if="answer.hint"
                        class="block text-muted-foreground"
                        >{{ answer.hint }}</span
                    >
                </td>
                <td
                    class="w-2/5 py-1.5 align-top max-sm:w-full max-sm:py-0"
                    :class="answer.score > 0 ? 'font-semibold' : ''"
                >
                    {{ answer.label }}
                    <span class="text-muted-foreground tabular-nums"
                        >({{ answer.score }})</span
                    >
                </td>
            </tr>
        </tbody>
    </table>
</template>
