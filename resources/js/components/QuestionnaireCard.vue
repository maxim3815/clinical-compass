<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, ClipboardList, HeartPulse } from '@lucide/vue';
import { computed } from 'vue';
import AssessmentController from '@/actions/App/Http/Controllers/Patient/AssessmentController';
import { Button } from '@/components/ui/button';
import { wellbeing } from '@/routes/patient';
import type { AssessmentStatus } from '@/types';

/**
 * Опросники на «Сегодня». Когда наступил срок — карточка с главной кнопкой «Ответить на вопросы»,
 * в остальные дни — строка-ссылка в раздел «Самочувствие» с датами.
 */
const props = defineProps<{
    status: AssessmentStatus;
}>();

const dates = computed(() =>
    props.status.last === null
        ? `Следующий раз — ${props.status.next}. Если стало хуже, можно ответить раньше.`
        : `Последний раз вы отвечали ${props.status.last}. Следующий раз — ${props.status.next}. Если стало хуже, можно ответить раньше.`,
);
</script>

<template>
    <section
        v-if="status.due"
        class="flex flex-col gap-3 rounded-2xl border border-primary/30 bg-secondary p-4 text-secondary-foreground"
        aria-labelledby="questionnaire-card-title"
        data-test="questionnaire-card"
    >
        <div class="flex items-start gap-3">
            <ClipboardList class="mt-0.5 size-6 shrink-0" />
            <div class="flex flex-col gap-1">
                <h2 id="questionnaire-card-title" class="text-xl font-semibold">
                    Вопросы о самочувствии
                </h2>
                <p>
                    Пора ответить, как вы себя чувствовали последние две недели.
                    Ответы увидит врач.
                </p>
            </div>
        </div>
        <Button size="xl" as-child>
            <Link
                :href="AssessmentController.create()"
                data-test="answer-questions"
                >Ответить на вопросы</Link
            >
        </Button>
    </section>

    <Link
        v-else
        :href="wellbeing()"
        class="flex items-center gap-4 rounded-2xl border bg-card p-4 shadow-xs outline-none hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
        data-test="wellbeing-link"
    >
        <span
            class="flex size-11 shrink-0 items-center justify-center rounded-full bg-secondary text-secondary-foreground"
        >
            <HeartPulse class="size-6" />
        </span>
        <span class="flex min-w-0 flex-1 flex-col">
            <span class="font-semibold">Самочувствие</span>
            <span class="text-muted-foreground">{{ dates }}</span>
        </span>
        <ChevronRight class="size-6 shrink-0 text-muted-foreground" />
    </Link>
</template>
