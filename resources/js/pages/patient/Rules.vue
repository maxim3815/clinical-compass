<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    CircleAlert,
    Clock,
    LifeBuoy,
    Phone,
    TriangleAlert,
} from '@lucide/vue';
import { nextTick, onMounted, ref, useTemplateRef } from 'vue';
import RulesController from '@/actions/App/Http/Controllers/Patient/RulesController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { show as help } from '@/routes/patient/help';
import { show } from '@/routes/patient/rules';
import { edit as profile } from '@/routes/profile';

/**
 * Правила «Как работает помощь в приложении»: новый пациент видит их сразу после регистрации
 * и нажимает «Понятно». Пока он не нажал, вкладок нет, а кнопка помощи в шапке есть. Потом
 * правила открываются из настроек и с экрана помощи. Просьба медика команды: приложение не служба
 * спасения, а «Сообщить врачу» — для срочного [медик].
 */
type Props = {
    acknowledged: boolean;
    // Открыты с экрана помощи: ссылка назад ведёт туда.
    fromHelp: boolean;
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: Props) => ({
        breadcrumbs: props.acknowledged
            ? [
                  props.fromHelp
                      ? { title: 'Помощь', href: help() }
                      : { title: 'Настройки', href: profile() },
                  {
                      title: 'Как работает помощь',
                      href: show(),
                  },
              ]
            : [],
    }),
});

const page = usePage();
const heading = useTemplateRef<HTMLElement>('heading');
const failure = useTemplateRef<HTMLElement>('failure');
const sending = ref(false);
// «Понятно» не дошло: нет сети или сервер не ответил.
const failed = ref(false);

// Новый экран сразу после регистрации: скринридер читает, где пациент.
onMounted(() => {
    if (!props.acknowledged) {
        heading.value?.focus();
    }
});

function acknowledge(): void {
    if (sending.value) {
        return;
    }

    let sent = false;

    router.post(
        RulesController.acknowledge.url(),
        {},
        {
            onStart: () => {
                sending.value = true;
                failed.value = false;
            },
            onSuccess: () => {
                sent = true;
            },
            onFinish: async () => {
                sending.value = false;
                failed.value = !sent;

                if (!sent) {
                    await nextTick();
                    failure.value?.focus();
                }
            },
        },
    );
}
</script>

<template>
    <Head title="Как работает помощь в приложении" />

    <div class="flex flex-col gap-6 p-4" data-test="rules">
        <header class="flex flex-col gap-1">
            <h1
                ref="heading"
                class="text-2xl font-semibold tracking-tight outline-none"
                tabindex="-1"
            >
                Как работает помощь в приложении
            </h1>
            <p class="text-muted-foreground">
                Приложение напоминает о приёме препаратов и показывает врачу,
                как вы себя чувствуете. Но это не служба спасения.
            </p>
        </header>

        <section class="flex items-start gap-3" aria-labelledby="rules-112">
            <Phone class="mt-1 size-6 shrink-0 text-primary" />
            <div class="flex flex-col gap-1">
                <h2 id="rules-112" class="text-xl font-semibold">
                    Если есть угроза жизни, звоните 112
                </h2>
                <p>
                    Приложение не вызывает скорую и не заменяет её. В 112
                    отвечают круглосуточно, звонок бесплатный.
                </p>
            </div>
        </section>

        <section class="flex items-start gap-3" aria-labelledby="rules-doctor">
            <Clock class="mt-1 size-6 shrink-0 text-primary" />
            <div class="flex flex-col gap-1">
                <h2 id="rules-doctor" class="text-xl font-semibold">
                    Врач ответит не сразу
                </h2>
                <p>
                    Врач видит ваши отметки, ответы и сообщения, когда
                    освободится: днём он ведёт приём, а ночью и в выходные не на
                    связи. Срочное сообщение он тоже может увидеть не сразу.
                </p>
                <!-- Номер прямо здесь: незачем открывать экран помощи, только чтобы его посмотреть. -->
                <p>
                    {{
                        page.props.crisisLine
                            ? `Если вам тяжело, а врач не отвечает, позвоните на кризисную линию по номеру ${page.props.crisisLine.label}: там поговорят и помогут.`
                            : 'Если вам тяжело, а врач не отвечает, позвоните на кризисную линию: там поговорят и помогут.'
                    }}
                </p>
            </div>
        </section>

        <section class="flex items-start gap-3" aria-labelledby="rules-help">
            <LifeBuoy class="mt-1 size-6 shrink-0 text-primary" />
            <div class="flex flex-col gap-1">
                <h2 id="rules-help" class="text-xl font-semibold">
                    Кнопка «Нужна помощь»
                </h2>
                <p>
                    Она вверху каждого экрана и открывает экран, где можно
                    позвонить в 112 или на кризисную линию и сообщить врачу, что
                    вам тяжело.
                </p>
                <p>
                    Нажмите «Сообщить врачу», если вам тяжело и врачу нужно
                    узнать об этом срочно: он получит срочное уведомление.
                    <!-- Пока пациента ведёт терапевт, чата нет. -->
                    {{
                        page.props.withTherapist
                            ? 'Если вопрос может подождать, задайте его врачу на приёме.'
                            : 'Если вопрос может подождать, напишите врачу в чат.'
                    }}
                </p>
                <p>
                    Врач видит, что вы открывали экран помощи и нажимали кнопки
                    звонков. Так он понимает, что вам, возможно, было тяжело, и
                    может спросить об этом. О чём вы говорили по телефону, врач
                    не узнает.
                </p>
            </div>
        </section>

        <!-- Окно побочных эффектов есть, только пока пациента ведёт психиатр. -->
        <section
            v-if="!page.props.withTherapist"
            class="flex items-start gap-3"
            aria-labelledby="rules-side-effects"
        >
            <TriangleAlert class="mt-1 size-6 shrink-0 text-primary" />
            <div class="flex flex-col gap-1">
                <h2 id="rules-side-effects" class="text-xl font-semibold">
                    Побочные эффекты
                </h2>
                <p>
                    Сообщить о побочном эффекте можно в разделе «Препараты», но
                    врач увидит это не сразу. Если вам плохо прямо сейчас,
                    звоните 112, не ждите ответа врача.
                </p>
            </div>
        </section>

        <div v-if="!acknowledged" class="flex flex-col gap-3">
            <p class="text-muted-foreground">
                Перечитать это можно в «Настройках», в разделе «Профиль».
            </p>
            <!-- Не ушло: заметно и не исчезает само, кнопка помощи в шапке работает. -->
            <div
                v-if="failed"
                ref="failure"
                role="alert"
                tabindex="-1"
                class="flex items-start gap-3 rounded-xl border border-danger-border bg-danger-subtle p-3 outline-none"
                data-test="rules-acknowledge-failed"
            >
                <CircleAlert class="mt-0.5 size-6 shrink-0 text-danger" />
                <p>Не получилось. Проверьте интернет и нажмите ещё раз.</p>
            </div>
            <Button
                size="xl"
                class="w-full"
                :aria-disabled="sending || undefined"
                data-test="rules-acknowledge"
                @click="acknowledge"
            >
                <Spinner v-if="sending" class="size-5" aria-hidden="true" />
                Понятно
            </Button>
        </div>
    </div>
</template>
