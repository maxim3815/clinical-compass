<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    ExternalLink,
    LifeBuoy,
    MessageCircle,
    Phone,
    Plus,
    UserRoundX,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { medications } from '@/routes/patient';
import { show as chat } from '@/routes/patient/chat';
import { show as help } from '@/routes/patient/help';
import {
    create,
    index as sideEffects,
    resolve,
    unresolve,
} from '@/routes/patient/side-effects';
import type { PatientSideEffect } from '@/types';

/**
 * Окно «Побочные эффекты»: что показать после отправки, «Сообщить о побочном эффекте»
 * и список «Вы сообщали» с «Уже прошло». После симптома «нужна помощь сейчас» первым идёт звонок
 * в 112 и слова листка о приёме препарата: это официальная инструкция, а не решение приложения.
 * Отметки «врач просмотрел» пациент не видит [медик].
 */
defineProps<{
    // Без лечащего врача сообщать некому.
    canReport: boolean;
    reports: PatientSideEffect[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Препараты',
                href: medications(),
            },
            {
                title: 'Побочные эффекты',
                href: sideEffects(),
            },
        ],
    },
});

const page = usePage();
// Только сразу после отправки: время и вид — по ответу сервера.
const report = computed(() => page.flash.sideEffectReport ?? null);
const result = useTemplateRef<HTMLElement>('result');
const resolving = ref<number | null>(null);

// Пациент сразу слышит, что ушло и что делать дальше.
onMounted(async () => {
    if (report.value !== null) {
        await nextTick();
        result.value?.focus();
    }
});

// «Уже прошло» и его отмена в тот же день. Кнопка меняется, поэтому фокус переходит на строку
// с новым состоянием этой записи.
function setResolved(item: PatientSideEffect, value: boolean): void {
    if (resolving.value !== null) {
        return;
    }

    const options = {
        preserveScroll: true,
        onStart: () => (resolving.value = item.id),
        onFinish: () => (resolving.value = null),
        onSuccess: async () => {
            await nextTick();
            document
                .querySelector<HTMLElement>(`[data-report-state="${item.id}"]`)
                ?.focus();
        },
    };

    if (value) {
        router.post(resolve.url(item.id), {}, options);
    } else {
        router.delete(unresolve.url(item.id), options);
    }
}
</script>

<template>
    <Head title="Побочные эффекты" />

    <div class="flex flex-col gap-6 p-4" data-test="side-effects-screen">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                Побочные эффекты
            </h1>
            <p class="text-muted-foreground">
                Если после начала приёма появилось что-то непривычное,
                расскажите врачу.
            </p>
        </header>

        <!-- Нужна помощь сейчас: звонок первым, сообщение врачу ниже. -->
        <section
            v-if="report?.kind === 'emergency'"
            ref="result"
            role="alert"
            tabindex="-1"
            class="flex flex-col gap-3 rounded-2xl border-2 border-danger bg-card p-4 outline-none"
            data-test="side-effect-emergency"
        >
            <div class="flex items-start gap-3">
                <CircleAlert class="mt-0.5 size-6 shrink-0 text-danger" />
                <div class="flex flex-col gap-1">
                    <h2 class="text-xl font-semibold">Позвоните 112 сейчас</h2>
                    <p>
                        Такой симптом может быть опасным. Не ждите ответа врача.
                    </p>
                </div>
            </div>
            <Button size="xl" as-child>
                <a href="tel:112" data-test="side-effect-call-112">
                    <Phone />
                    Позвонить 112
                </a>
            </Button>
            <!-- Слова листков — спокойным блоком: главное здесь звонок. -->
            <div
                v-if="report.instructions.length > 0"
                class="flex flex-col gap-2 rounded-xl border bg-muted/40 p-3"
                data-test="side-effect-instructions"
            >
                <h3 class="font-semibold">Что сказано в инструкции</h3>
                <div
                    v-for="instruction in report.instructions"
                    :key="`${instruction.drug}-${instruction.text}`"
                    class="flex flex-col"
                    data-test="side-effect-instruction"
                >
                    <p>
                        {{
                            `${instruction.drug}: «${instruction.text.replace(/\.$/, '')}».`
                        }}
                    </p>
                    <a
                        :href="instruction.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex min-h-11 items-center gap-1 self-start text-primary underline underline-offset-4"
                    >
                        Открыть инструкцию
                        <span class="sr-only">{{
                            `к препарату «${instruction.drug}» (в новой вкладке)`
                        }}</span>
                        <ExternalLink class="size-4 shrink-0" />
                    </a>
                </div>
            </div>
            <p class="flex items-start gap-2 text-muted-foreground">
                <CircleCheck class="mt-0.5 size-5 shrink-0 text-success" />
                {{ `Врачу тоже отправлено в\u00a0${report.at}.` }}
            </p>
        </section>

        <!-- Сообщить врачу сразу или обычный симптом: что ушло и что делать дальше. -->
        <section
            v-else-if="report"
            ref="result"
            role="status"
            tabindex="-1"
            class="flex flex-col gap-3 rounded-2xl border border-success-border bg-success-subtle p-4 outline-none"
            data-test="side-effect-sent"
        >
            <div class="flex items-start gap-3">
                <CircleCheck class="mt-0.5 size-6 shrink-0 text-success" />
                <div class="flex flex-col gap-1">
                    <h2 class="text-xl font-semibold">
                        {{
                            report.kind === 'urgent'
                                ? `Врачу отправлено срочно в\u00a0${report.at}`
                                : `Врачу отправлено в\u00a0${report.at}`
                        }}
                    </h2>
                    <p>
                        {{
                            report.kind === 'urgent'
                                ? 'Врач получит срочное уведомление, но ответ может прийти не сразу.'
                                : 'Врач увидит это в вашей карточке. Если хотите что-то добавить, напишите ему.'
                        }}
                    </p>
                </div>
            </div>
            <Button
                variant="outline"
                size="lg"
                class="rounded-full bg-card"
                as-child
            >
                <Link :href="chat()" data-test="side-effect-chat">
                    <MessageCircle />
                    Написать врачу
                </Link>
            </Button>
            <template v-if="report.kind === 'urgent'">
                <p>Если станет хуже, звоните 112.</p>
                <Button
                    variant="outline"
                    size="lg"
                    class="rounded-full bg-card"
                    as-child
                >
                    <a href="tel:112" data-test="side-effect-call-112">
                        <Phone />
                        Позвонить 112
                    </a>
                </Button>
            </template>
        </section>

        <!-- После отправки главное — в блоке результата, «Сообщить» становится второстепенной. -->
        <Button
            v-if="canReport"
            :size="report ? 'lg' : 'xl'"
            :variant="report ? 'outline' : 'default'"
            :class="report ? 'rounded-full' : ''"
            as-child
        >
            <Link :href="create()" data-test="report-side-effect">
                <Plus />
                Сообщить о побочном эффекте
            </Link>
        </Button>

        <div
            v-else
            class="flex flex-col gap-3 rounded-2xl border border-dashed p-4"
            data-test="side-effects-no-doctor"
        >
            <div class="flex items-start gap-3">
                <UserRoundX
                    class="mt-0.5 size-6 shrink-0 text-muted-foreground"
                />
                <p>
                    Вы сейчас не подключены к врачу в приложении, поэтому
                    сообщить ему отсюда нельзя. Если стало плохо, позвоните 112.
                </p>
            </div>
            <Button size="lg" variant="outline" class="rounded-full" as-child>
                <a href="tel:112">
                    <Phone />
                    Позвонить 112
                </a>
            </Button>
        </div>

        <!--
            Опасные симптомы и мысли о вреде себе — не в форму, а сразу к людям. Сразу после
            отправки не показываем: 112 уже есть в блоке результата.
        -->
        <div
            v-if="!report"
            class="flex flex-col gap-2 rounded-2xl bg-secondary p-4 text-secondary-foreground"
            data-test="side-effects-warning"
        >
            <p>
                Если трудно дышать, отекло лицо или губы, начались судороги,
                сразу звоните 112.
            </p>
            <p>Если появились мысли навредить себе, откройте экран помощи.</p>
            <Link
                :href="help()"
                class="-mx-2 inline-flex min-h-11 items-center gap-2 self-start rounded-lg px-2 font-medium text-primary underline-offset-4 outline-none hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                data-test="side-effects-help-link"
            >
                <LifeBuoy class="size-5 shrink-0" />
                Нужна помощь
            </Link>
        </div>

        <section
            class="flex flex-col gap-3"
            aria-labelledby="reports-title"
            data-test="side-effect-reports"
        >
            <h2 id="reports-title" class="text-xl font-semibold">
                Вы сообщали
            </h2>

            <p
                v-if="reports.length === 0"
                class="rounded-2xl border border-dashed p-4 text-muted-foreground"
            >
                Пока ничего. Здесь будет видно, о чём вы сообщили врачу.
            </p>

            <ul v-else class="flex flex-col gap-3">
                <li
                    v-for="item in reports"
                    :key="item.id"
                    class="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-xs"
                    data-test="side-effect-report"
                >
                    <div class="flex flex-col gap-1">
                        <p class="font-semibold break-words">{{ item.name }}</p>
                        <p class="text-muted-foreground">
                            {{
                                `${item.period}, ${item.severity}${item.by_doctor ? ' · записал врач' : ''}`
                            }}
                        </p>
                    </div>
                    <div
                        v-if="item.resolved"
                        class="flex flex-wrap items-center gap-x-4 gap-y-2"
                    >
                        <p
                            class="flex items-center gap-2 font-medium text-success outline-none"
                            tabindex="-1"
                            :data-report-state="item.id"
                            data-test="side-effect-resolved"
                        >
                            <CircleCheck class="size-5 shrink-0" />
                            Прошло<span class="sr-only">{{
                                `: ${item.name}`
                            }}</span>
                        </p>
                        <!-- Случайное «Уже прошло» отменяется в тот же день. -->
                        <Button
                            v-if="item.undoable"
                            variant="outline"
                            size="lg"
                            class="rounded-full"
                            :aria-disabled="resolving === item.id || undefined"
                            :aria-label="`Не прошло: ${item.name}`"
                            data-test="side-effect-unresolve"
                            @click="setResolved(item, false)"
                        >
                            <Spinner
                                v-if="resolving === item.id"
                                class="size-5"
                                aria-hidden="true"
                            />
                            Не прошло
                        </Button>
                    </div>
                    <Button
                        v-else
                        variant="outline"
                        size="lg"
                        class="self-start rounded-full"
                        :aria-disabled="resolving === item.id || undefined"
                        :aria-label="`Уже прошло: ${item.name}`"
                        :data-report-state="item.id"
                        data-test="side-effect-resolve"
                        @click="setResolved(item, true)"
                    >
                        <Spinner
                            v-if="resolving === item.id"
                            class="size-5"
                            aria-hidden="true"
                        />
                        Уже прошло
                    </Button>
                </li>
            </ul>
        </section>
    </div>
</template>
