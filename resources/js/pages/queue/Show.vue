<script setup lang="ts">
import { Head, useRemember } from '@inertiajs/vue3';
import { LifeBuoy } from '@lucide/vue';
import { computed, nextTick, onMounted, reactive, useTemplateRef } from 'vue';
import QueueController from '@/actions/App/Http/Controllers/QueueController';
import HelpCallButtons from '@/components/HelpCallButtons.vue';
import QuestionnaireForm from '@/components/QuestionnaireForm.vue';
import { Button } from '@/components/ui/button';
import { doctorLine } from '@/lib/doctor';
import { plural } from '@/lib/plural';
import type { InvitingDoctor, QuestionnaireScale } from '@/types';

/**
 * QR-код у двери терапевта: пациент в очереди без входа читает пояснение, отвечает на опросники
 * и получает код для приёма. Своих баллов не видит: интерпретация у врача [медик]. Если ответы
 * подпадают под правило алертинга, на экране кода — 112, кризисная линия и просьба сказать код
 * терапевту без очереди.
 */
const props = defineProps<{
    joinCode: string;
    doctor: InvitingDoctor;
    // Код этого телефона: ответы уже отправлены. expires_at — «сегодня в 22:00» или «завтра в 08:39».
    answer: { code: string; expires_at: string; help: boolean } | null;
    scales: QuestionnaireScale[];
    lifetimeHours: number;
}>();

// Пояснение прочитано: после перезагрузки страницы пациент остаётся в вопросах.
const progress = useRemember(reactive({ started: false }), 'queue-started') as {
    started: boolean;
};

const questions = computed(() =>
    props.scales.reduce((sum, scale) => sum + scale.items.length, 0),
);

const intro = computed(
    () =>
        `Пока вы ждёте приёма, ответьте на ${questions.value} ${plural(questions.value, ['вопрос', 'вопроса', 'вопросов'])} о том, как вы себя чувствовали последние 2 недели. Это займёт 3–5 минут.`,
);

// Для скринридера цифры по одной: «4 8 2 1», а не «четыре тысячи…».
const spokenCode = computed(() => props.answer?.code.split('').join(' ') ?? '');

const heading = useTemplateRef<HTMLElement>('heading');

// После «Начать» фокус на заголовке первой части: скринридер читает, с чего начинать.
async function start(): Promise<void> {
    progress.started = true;
    await nextTick();
    window.scrollTo({ top: 0 });
    document.querySelector<HTMLElement>('main h1')?.focus();
}

// После отправки фокус на заголовке: скринридер сразу читает, что делать с кодом.
onMounted(() => {
    if (props.answer !== null) {
        heading.value?.focus();
    }
});
</script>

<template>
    <Head :title="answer ? 'Ваш код для приёма' : 'Вопросы о самочувствии'" />

    <div v-if="answer" class="flex flex-col gap-6 p-4" data-test="queue-code">
        <section
            class="flex flex-col items-center gap-3 rounded-2xl border bg-card p-6 text-center shadow-xs"
            :class="answer.help ? 'border-primary/40' : ''"
            aria-labelledby="queue-code-title"
        >
            <h1
                id="queue-code-title"
                ref="heading"
                class="text-2xl font-semibold tracking-tight outline-none"
                tabindex="-1"
            >
                Ваш код для приёма
            </h1>
            <p
                class="text-6xl font-semibold tracking-[0.2em] tabular-nums"
                data-test="queue-code-value"
            >
                <span aria-hidden="true">{{ answer.code }}</span>
                <span class="sr-only">{{ spokenCode }}</span>
            </p>
            <p v-if="answer.help" class="text-lg font-medium">
                Постучите в кабинет и назовите код терапевту, можно без очереди.
            </p>
            <p v-else class="text-lg">
                Назовите или покажите этот код терапевту на приёме.
            </p>
        </section>

        <!-- Экран помощи: 112 и кризисная линия. Чата здесь нет: пациент ещё не в приложении. -->
        <section
            v-if="answer.help"
            class="flex flex-col gap-3 rounded-2xl border border-primary/30 bg-secondary p-4 text-secondary-foreground"
            aria-labelledby="queue-help-title"
            data-test="queue-help"
        >
            <div class="flex items-start gap-3">
                <LifeBuoy class="mt-0.5 size-6 shrink-0" />
                <div class="flex flex-col gap-1">
                    <h2 id="queue-help-title" class="text-xl font-semibold">
                        Если вам сейчас тяжело
                    </h2>
                    <p>
                        Позвоните 112 или на кризисную линию. Звонок бесплатный.
                    </p>
                </div>
            </div>
            <HelpCallButtons :record="false" />
        </section>

        <div class="flex flex-col gap-2 text-muted-foreground">
            <p>
                {{
                    `Ответы увидит только ваш терапевт, ${doctor.name}. Они удалятся сами ${answer.expires_at}, а если на приёме вы зарегистрируетесь в приложении, сохранятся у вас.`
                }}
            </p>
            <p>
                Страницу можно закрыть: если снова открыть QR-код на этом
                телефоне, код покажется ещё раз.
            </p>
        </div>
    </div>

    <QuestionnaireForm
        v-else-if="progress.started"
        class="p-4"
        :scales="scales"
        :action="QueueController.store.url(joinCode)"
        submit-label="Получить код"
        remember-key="queue-questionnaire"
    />

    <div v-else class="flex flex-col gap-6 p-4" data-test="queue-intro">
        <header class="flex flex-col gap-2">
            <h1 class="text-2xl font-semibold tracking-tight">
                Вопросы о самочувствии
            </h1>
            <p class="text-muted-foreground">
                {{ `Приём ведёт: ${doctorLine(doctor)}` }}
            </p>
        </header>

        <p class="text-lg">{{ intro }}</p>

        <ul class="flex list-disc flex-col gap-2 pl-6">
            <li>
                Регистрироваться не нужно. Имя, телефон и номер полиса мы не
                спрашиваем.
            </li>
            <li>
                В конце вы получите код из 4 цифр. Назовите его на приёме: по
                коду терапевт откроет ваши ответы и не будет тратить время
                приёма на те же вопросы.
            </li>
            <li>Ответы увидит только этот терапевт.</li>
            <li>
                {{
                    `Ответы удалятся сами через ${lifetimeHours} ${plural(lifetimeHours, ['час', 'часа', 'часов'])}. Если на приёме вы зарегистрируетесь в приложении, они сохранятся у вас.`
                }}
            </li>
        </ul>

        <div class="flex flex-col gap-3">
            <p class="text-muted-foreground">
                Нажимая «Начать», вы соглашаетесь, что терапевт увидит ваши
                ответы.
            </p>
            <Button size="xl" data-test="queue-start" @click="start">
                Начать
            </Button>
        </div>
    </div>
</template>
