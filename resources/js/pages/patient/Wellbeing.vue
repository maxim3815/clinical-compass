<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardList, ExternalLink } from '@lucide/vue';
import AssessmentController from '@/actions/App/Http/Controllers/Patient/AssessmentController';
import ScoreChart from '@/components/ScoreChart.vue';
import { Button } from '@/components/ui/button';
import { home, wellbeing } from '@/routes/patient';
import type { AssessmentStatus, PatientScale } from '@/types';

defineProps<{
    // null — опросников нет или пациент не прикреплён к врачу.
    status: AssessmentStatus | null;
    scales: PatientScale[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Сегодня',
                href: home(),
            },
            {
                title: 'Самочувствие',
                href: wellbeing(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Самочувствие" />

    <!--
        Суммы ответов по датам, по каждому опроснику отдельно. Степеней и слов о диагнозах
        пациент не видит: интерпретация только у врача.
    -->
    <div class="flex flex-col gap-6 p-4">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Самочувствие</h1>
            <p class="text-muted-foreground">
                Как менялись ваши ответы на вопросы о самочувствии. Чем ниже
                число, тем лучше.
            </p>
        </header>

        <section
            v-if="status?.due"
            class="flex flex-col gap-3 rounded-2xl border border-primary/30 bg-secondary p-4 text-secondary-foreground"
            data-test="wellbeing-due"
        >
            <div class="flex items-start gap-3">
                <ClipboardList class="mt-0.5 size-6 shrink-0" />
                <div class="flex flex-col gap-1">
                    <h2 class="text-lg font-semibold">
                        Пора ответить на вопросы
                    </h2>
                    <p>
                        Как вы себя чувствовали последние две недели. Ответы
                        увидит врач.
                    </p>
                </div>
            </div>
            <Button size="xl" as-child>
                <Link :href="AssessmentController.create()"
                    >Ответить на вопросы</Link
                >
            </Button>
        </section>

        <section
            v-else-if="status"
            class="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-xs"
            data-test="wellbeing-next"
        >
            <p v-if="status.last">
                Последний раз вы отвечали {{ status.last }}.
            </p>
            <p>
                Следующий раз —
                <span class="font-semibold">{{ status.next }}</span
                >. Если самочувствие изменилось, ответьте на вопросы в любой
                день: врач увидит ответы.
            </p>
            <Button variant="outline" size="lg" as-child>
                <Link
                    :href="AssessmentController.create()"
                    data-test="answer-now"
                    >Ответить сейчас</Link
                >
            </Button>
        </section>

        <p
            v-else
            class="rounded-2xl border border-dashed p-4 text-muted-foreground"
        >
            Вопросы о самочувствии появятся, когда вас прикрепят к врачу.
        </p>

        <section
            v-for="scale in scales"
            :key="scale.code"
            class="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-xs"
            :aria-labelledby="`scale-${scale.code}-title`"
            :data-test="`wellbeing-${scale.code}`"
        >
            <div class="flex flex-col gap-1">
                <h2
                    :id="`scale-${scale.code}-title`"
                    class="text-xl font-semibold"
                >
                    {{ scale.title }}
                </h2>
                <p class="text-muted-foreground">
                    Опросник {{ scale.name }}: сумма от 0 до {{ scale.max }},
                    чем ниже, тем лучше.
                </p>
            </div>

            <ScoreChart
                v-if="scale.points.length"
                :points="scale.points"
                :max="scale.max"
                :label="`${scale.title}, сумма баллов по датам`"
                large
            />
            <p
                v-else
                class="rounded-xl border border-dashed p-4 text-muted-foreground"
            >
                Вы ещё не отвечали на эти вопросы. Ответы появятся здесь.
            </p>
        </section>

        <section
            v-if="scales.length"
            class="flex flex-col gap-3 text-muted-foreground"
            aria-labelledby="sources-title"
        >
            <h2 id="sources-title" class="font-semibold text-foreground">
                Источники
            </h2>
            <div
                v-for="scale in scales"
                :key="`source-${scale.code}`"
                class="flex flex-col gap-1"
            >
                <p>{{ scale.name }}: {{ scale.source.validation }}</p>
                <p>{{ scale.source.license }}</p>
                <a
                    :href="scale.source.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex min-h-11 items-center gap-1 self-start text-primary underline underline-offset-4"
                >
                    Открыть документ<span class="sr-only">{{
                        ` ${scale.name}`
                    }}</span>
                    <ExternalLink class="size-4" />
                </a>
            </div>
        </section>
    </div>
</template>
