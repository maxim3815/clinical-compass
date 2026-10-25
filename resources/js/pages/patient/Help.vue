<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    BellRing,
    CircleAlert,
    CircleCheck,
    Info,
    MessageCircle,
    UserRoundX,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue';
import HelpController from '@/actions/App/Http/Controllers/Patient/HelpController';
import HelpCallButtons from '@/components/HelpCallButtons.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { home } from '@/routes/patient';
import { show as chat } from '@/routes/patient/chat';
import { show } from '@/routes/patient/help';
import { show as rules } from '@/routes/patient/rules';

/**
 * Экран помощи: открывается кнопкой в шапке с любого экрана. Сначала звонки в 112 и на кризисную
 * линию, ниже «Сообщить врачу» — срочный сигнал врачу без подтверждения — и чат. Приложение
 * не обещает, что врач ответит или перезвонит: звонки остаются на экране и после сообщения [медик].
 */
defineProps<{
    // Лечащий врач: «Сообщить врачу» и «Написать врачу» есть, только если он есть.
    doctor: string | null;
    // Есть врач и действующее правило по кнопке.
    canReport: boolean;
    // Пациент недавно уже нажимал «Сообщить врачу»: «сегодня в 14:52». Своё действие, не статус алерта.
    lastReport: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Сегодня',
                href: home(),
            },
            {
                title: 'Помощь',
                href: show(),
            },
        ],
    },
});

const page = usePage();
// Время отправки — только сразу после нажатия, по часовому поясу пациента.
const reportedAt = computed(() => page.flash.helpReportedAt ?? null);
const reporting = ref(false);
// Сообщение не ушло: нет сети, сервер не ответил или врача больше нет.
const failed = ref(false);
// Сервер ответил, но сообщить врачу нельзя: повторять нажатие бесполезно.
const refused = computed(() => page.flash.helpReportRefused === true);
const heading = useTemplateRef<HTMLElement>('heading');
const status = useTemplateRef<HTMLElement>('status');
const failure = useTemplateRef<HTMLElement>('failure');

// Фокус на заголовке: скринридер сразу читает, где пациент.
onMounted(() => {
    if (reportedAt.value === null) {
        heading.value?.focus();
    }
});

function report(): void {
    // Пока сообщение уходит, второе нажатие ничего не делает, а фокус остаётся на кнопке.
    if (reporting.value) {
        return;
    }

    let sent = false;

    router.post(
        HelpController.report.url(),
        {},
        {
            preserveScroll: true,
            // «Назад» с экрана помощи — туда, откуда пациент пришёл, а не на этот же экран.
            replace: true,
            onStart: () => {
                reporting.value = true;
                failed.value = false;
            },
            onSuccess: (response) => {
                sent = Boolean(response.flash.helpReportedAt);
            },
            onFinish: async () => {
                reporting.value = false;
                failed.value = !sent;
                await nextTick();
                (sent ? status : failure).value?.focus();
            },
        },
    );
}
</script>

<template>
    <Head title="Помощь" />

    <div class="flex flex-col gap-6 p-4" data-test="help-screen">
        <header class="flex flex-col gap-1">
            <h1
                ref="heading"
                class="text-2xl font-semibold tracking-tight outline-none"
                tabindex="-1"
            >
                Если вам сейчас тяжело
            </h1>
            <p class="text-muted-foreground">
                Если есть угроза жизни, звоните 112: отвечают круглосуточно. На
                кризисной линии поговорят и помогут. Оба звонка бесплатные.
            </p>
        </header>

        <HelpCallButtons />

        <section
            v-if="doctor"
            class="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-xs"
            aria-labelledby="help-doctor-title"
            data-test="help-doctor"
        >
            <div class="flex flex-col gap-1">
                <h2 id="help-doctor-title" class="text-xl font-semibold">
                    Ваш врач
                </h2>
                <p class="font-medium break-words">{{ doctor }}</p>
            </div>

            <!-- Сразу после нажатия: что ушло, чего ждать и что делать, если нажали случайно. -->
            <div
                v-if="reportedAt && !failed"
                ref="status"
                role="status"
                tabindex="-1"
                class="flex flex-col gap-3 rounded-xl border border-success-border bg-success-subtle p-3 outline-none"
                data-test="help-reported"
            >
                <div class="flex items-start gap-3">
                    <CircleCheck class="mt-0.5 size-6 shrink-0 text-success" />
                    <div class="flex flex-col gap-1">
                        <p class="font-semibold">
                            {{ `Врачу отправлено в ${reportedAt}` }}
                        </p>
                        <p>
                            Ответ может прийти не сразу. Если есть угроза жизни,
                            звоните 112.
                        </p>
                        <p v-if="!page.props.withTherapist">
                            Если нажали случайно, напишите врачу:
                        </p>
                    </div>
                </div>
                <Button
                    v-if="!page.props.withTherapist"
                    variant="outline"
                    size="lg"
                    class="rounded-full bg-card"
                    as-child
                >
                    <Link :href="chat()" data-test="help-reported-chat">
                        <MessageCircle />
                        Написать врачу
                    </Link>
                </Button>
            </div>

            <!-- Не ушло: заметно и не исчезает само, звонок в 112 работает и без интернета. -->
            <div
                v-if="failed"
                ref="failure"
                role="alert"
                tabindex="-1"
                class="flex items-start gap-3 rounded-xl border border-danger-border bg-danger-subtle p-3 outline-none"
                data-test="help-report-failed"
            >
                <CircleAlert class="mt-0.5 size-6 shrink-0 text-danger" />
                <div class="flex flex-col gap-1">
                    <p class="font-semibold">Врачу не отправлено</p>
                    <p>
                        {{
                            refused
                                ? 'Сообщить врачу отсюда сейчас нельзя. Если вам тяжело, позвоните 112 или на кризисную линию.'
                                : 'Проверьте интернет и нажмите ещё раз. Если есть угроза жизни, звоните 112: звонок работает и без интернета.'
                        }}
                    </p>
                </div>
            </div>

            <template v-if="canReport">
                <!-- До нажатия: что произойдёт. Подтверждения нет, поэтому подсказка — над кнопкой. -->
                <p
                    v-if="!reportedAt"
                    id="help-report-hint"
                    class="text-muted-foreground"
                    data-test="help-report-hint"
                >
                    {{
                        lastReport
                            ? `Вы сообщали врачу ${lastReport}. Если нужно, сообщите ещё раз: врач получит срочное уведомление.`
                            : 'Врач получит срочное уведомление, но ответ может прийти не сразу.'
                    }}
                </p>
                <!-- После отправки повтор тише и дальше от «Написать врачу»: не перепутать. -->
                <Button
                    :variant="reportedAt ? 'ghost' : 'secondary'"
                    :size="reportedAt ? 'lg' : 'xl'"
                    :class="
                        reportedAt
                            ? 'mt-3 rounded-full text-primary'
                            : 'border border-primary/60'
                    "
                    :aria-disabled="reporting || undefined"
                    :aria-describedby="
                        reportedAt ? undefined : 'help-report-hint'
                    "
                    data-test="help-report"
                    @click="report"
                >
                    <Spinner
                        v-if="reporting"
                        class="size-5"
                        aria-hidden="true"
                    />
                    <BellRing v-else />
                    {{
                        reporting
                            ? 'Отправляем врачу…'
                            : reportedAt || lastReport
                              ? 'Сообщить ещё раз'
                              : 'Сообщить врачу'
                    }}
                </Button>
            </template>

            <Button
                v-if="(!reportedAt || failed) && !page.props.withTherapist"
                variant="outline"
                size="lg"
                class="rounded-full"
                as-child
            >
                <Link :href="chat()" data-test="help-write-doctor">
                    <MessageCircle />
                    Написать врачу
                </Link>
            </Button>
        </section>

        <div
            v-else
            class="flex items-start gap-4 rounded-2xl border border-dashed p-4"
            data-test="help-no-doctor"
        >
            <UserRoundX class="mt-0.5 size-6 shrink-0 text-muted-foreground" />
            <p>
                Вы сейчас не подключены к врачу в приложении, поэтому сообщить
                ему отсюда нельзя. Позвоните 112 или на кризисную линию.
            </p>
        </div>

        <!-- Тихо и в самом низу: не спорит со звонками и «Сообщить врачу». -->
        <Link
            :href="rules({ query: { from: 'help' } })"
            class="inline-flex min-h-11 items-center gap-2 self-start text-base font-medium text-primary underline-offset-4 outline-none hover:underline focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-solid"
            data-test="help-rules-link"
        >
            <Info class="size-5 shrink-0" />
            Как работает помощь в приложении
        </Link>
    </div>
</template>
