<script setup lang="ts">
import { Link, useHttp, usePage } from '@inertiajs/vue3';
import { CircleCheck, Clock, Printer, QrCode, RefreshCw } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import AppointmentInvitationController from '@/actions/App/Http/Controllers/Doctor/AppointmentInvitationController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useAppointmentPin } from '@/composables/useAppointmentPin';
import type { AppointmentStage } from '@/composables/useAppointmentPin';
import { useRealtime } from '@/composables/useRealtime';
import { card } from '@/routes/doctor/invitations';
import { show as patientCard } from '@/routes/doctor/patients';
import type { AppointmentPin, InvitationChanged } from '@/types';

/**
 * «Пригласить на приёме»: PIN на 15 минут, QR постоянного адреса врача и что происходит
 * у пациента — ввёл код, зарегистрировался. Код, который врач уже назвал, случайно не гаснет:
 * окно не закрывается щелчком мимо, а открытое снова показывает тот же код, пока он действует.
 */
const { variant = 'outline' } = defineProps<{
    variant?: 'default' | 'outline';
}>();

const page = usePage();
const open = ref(false);
const { issued, deadline, stage, patient } = useAppointmentPin();
const failed = ref(false);
// Окно открыто снова: сервер подтверждает, что прежний код ещё ждёт ввода.
const checking = ref(false);
const left = ref(0);
// Новый код для скринридера: цифры на экране он сам не прочитает.
const announcement = ref('');
const closeButton = useTemplateRef<{ $el: HTMLElement }>('closeButton');

const http = useHttp<Record<string, never>, AppointmentPin>({});
const status = useHttp<
    Record<string, never>,
    {
        stage: AppointmentStage | 'closed';
        patient: InvitationChanged['patient'];
        expires_in: number;
    }
>({});

let timer: ReturnType<typeof setInterval> | undefined;

const waiting = computed(
    () => issued.value !== null && stage.value === 'waiting' && left.value > 0,
);
const expired = computed(
    () =>
        issued.value !== null && stage.value === 'waiting' && left.value === 0,
);

const countdown = computed(() => {
    const minutes = Math.floor(left.value / 60);
    const seconds = String(left.value % 60).padStart(2, '0');

    return `${minutes}:${seconds}`;
});

// Для скринридера цифры по одной: «7 3 9 2», а не «семь тысяч триста…».
const spokenPin = computed(() => {
    const digits = issued.value?.pin.split('').join(' ') ?? '';

    if (expired.value) {
        return `${digits}, истёк`;
    }

    return stage.value === 'waiting' ? digits : `${digits}, использован`;
});

function stopTimer(): void {
    clearInterval(timer);
    timer = undefined;
}

function tick(): void {
    left.value = Math.max(0, Math.ceil((deadline.value - Date.now()) / 1000));

    if (left.value === 0) {
        stopTimer();
    }
}

function startTimer(): void {
    stopTimer();
    tick();

    if (left.value > 0) {
        timer = setInterval(tick, 1000);
    }
}

function announce(pin: string): void {
    announcement.value = `Код для пациента: ${pin.split('').join(' ')}`;
}

async function issue(): Promise<void> {
    if (http.processing) {
        return;
    }

    stopTimer();
    failed.value = false;

    try {
        const response = await http.post(
            AppointmentInvitationController.store.url(),
        );
        issued.value = response;
        stage.value = 'waiting';
        patient.value = null;
        deadline.value = Date.now() + response.expires_in * 1000;
        startTimer();
        announce(response.pin);
    } catch {
        issued.value = null;
        failed.value = true;
    }

    // Кнопка «Новый код» внизу пропала вместе с истёкшим кодом: фокус не теряется.
    await nextTick();

    if (open.value && document.activeElement === document.body) {
        closeButton.value?.$el.focus();
    }
}

// Тот же код, если его никто не ввёл и он не истёк (и на другой странице кабинета тоже):
// новый погасил бы код, который врач уже назвал вслух.
async function reopen(): Promise<void> {
    if (issued.value === null || stage.value !== 'waiting') {
        return issue();
    }

    checking.value = true;

    try {
        const current = await status.get(
            AppointmentInvitationController.show.url(issued.value.id),
        );

        if (current.stage === 'waiting' && current.expires_in > 0) {
            deadline.value = Date.now() + current.expires_in * 1000;
            startTimer();
            announce(issued.value.pin);

            return;
        }
    } catch {
        // Не узнали, что с прежним кодом: выдаём новый.
    } finally {
        checking.value = false;
    }

    return issue();
}

watch(open, (value) => {
    if (value) {
        void reopen();
    } else {
        stopTimer();
        // Открыли снова — тот же код объявится заново.
        announcement.value = '';
    }
});

onBeforeUnmount(stopTimer);

// Первым в окне — безопасное «Закрыть», а не ссылка, которая уводит со страницы.
function focusClose(event: Event): void {
    event.preventDefault();
    closeButton.value?.$el.focus();
}

useRealtime<InvitationChanged>(
    `App.Models.User.${page.props.auth.user.id}`,
    'invitation.changed',
    (event) => {
        if (event.id !== issued.value?.id) {
            return;
        }

        // Событие о вводе кода может прийти после события о регистрации.
        if (stage.value !== 'registered') {
            stage.value = event.stage;
            patient.value = event.patient;
        }

        stopTimer();
    },
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button :variant="variant" data-test="appointment-invitation">
                <QrCode />
                Пригласить на приёме
            </Button>
        </DialogTrigger>
        <!-- Окно закреплено от верха: при смене статуса код не прыгает. -->
        <DialogContent
            class="sm:top-[10vh] sm:max-h-[calc(90dvh-1rem)] sm:max-w-2xl sm:translate-y-0"
            data-test="appointment-invitation-dialog"
            @pointer-down-outside.prevent
            @open-auto-focus="focusClose"
        >
            <DialogHeader>
                <DialogTitle>Пригласить на приёме</DialogTitle>
                <DialogDescription>
                    Попросите пациента навести камеру телефона на QR-код и
                    назовите ему код.
                </DialogDescription>
            </DialogHeader>

            <p class="sr-only" aria-live="polite">{{ announcement }}</p>

            <p
                v-if="failed"
                class="text-sm font-medium text-destructive"
                role="alert"
            >
                Не удалось получить код. Проверьте соединение и попробуйте ещё
                раз.
            </p>

            <p
                v-else-if="issued === null || checking"
                class="flex items-center gap-2 text-muted-foreground"
            >
                <Spinner />
                {{ checking ? 'Проверяем код…' : 'Создаём код…' }}
            </p>

            <!-- Код первым и в разметке, и на телефоне: его называют вслух. На компьютере QR слева. -->
            <div
                v-else
                class="grid grid-cols-[minmax(0,1fr)] gap-6 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-start"
            >
                <div class="flex flex-col gap-3">
                    <div class="flex flex-col gap-1">
                        <p class="text-sm text-muted-foreground">
                            Код для пациента
                        </p>
                        <p
                            class="text-6xl font-semibold tracking-[0.2em] tabular-nums"
                            :class="{
                                'text-muted-foreground line-through': !waiting,
                            }"
                            data-test="appointment-pin"
                        >
                            <span aria-hidden="true">{{ issued.pin }}</span>
                            <span class="sr-only">{{ spokenPin }}</span>
                        </p>
                        <p
                            v-if="waiting"
                            class="text-sm tabular-nums"
                            :class="
                                left < 60
                                    ? 'font-medium text-warning'
                                    : 'text-muted-foreground'
                            "
                        >
                            Действует ещё {{ countdown }} ·
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-sm text-foreground underline underline-offset-4 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid aria-disabled:opacity-60 pointer-coarse:py-2"
                                :aria-disabled="http.processing"
                                data-test="appointment-new-pin"
                                @click="issue"
                            >
                                <RefreshCw class="size-3.5" />
                                Новый код
                            </button>
                        </p>
                    </div>

                    <p class="text-sm text-muted-foreground">
                        Без камеры — открыть адрес и ввести код:
                        <span
                            class="block text-base font-semibold break-words text-foreground sm:text-lg"
                            data-test="appointment-address"
                            >{{ issued.address.short }}</span
                        >
                    </p>

                    <!-- Что происходит у пациента; меняется событием, без перезагрузки. -->
                    <div aria-live="polite">
                        <p
                            v-if="stage === 'entered'"
                            class="flex gap-2 rounded-lg border bg-muted p-3"
                            data-test="appointment-status"
                        >
                            <Clock
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            />
                            Код введён, пациент заполняет форму. Окно можно
                            закрыть: пациент появится в списке, когда закончит.
                        </p>
                        <div
                            v-else-if="stage === 'registered'"
                            class="flex flex-col gap-3 rounded-lg border border-success-border bg-success-subtle p-3"
                            data-test="appointment-status"
                        >
                            <p class="flex gap-2 font-medium">
                                <CircleCheck
                                    class="mt-0.5 size-4 shrink-0 text-success"
                                />
                                <!-- Без рода: пол пациента при регистрации ещё неизвестен. -->
                                {{
                                    patient
                                        ? `Регистрация завершена: ${patient.name}.`
                                        : 'Регистрация завершена.'
                                }}
                            </p>
                            <Button v-if="patient" as-child class="self-start">
                                <Link
                                    :href="patientCard(patient.id)"
                                    data-test="appointment-open-patient"
                                    >Открыть карточку</Link
                                >
                            </Button>
                        </div>
                        <p
                            v-else-if="expired"
                            class="font-medium text-destructive"
                            data-test="appointment-status"
                        >
                            Код истёк. Создайте новый.
                        </p>
                    </div>
                </div>

                <div
                    class="mx-auto size-56 rounded-xl border bg-white p-2 sm:order-first [&>svg]:size-full"
                    role="img"
                    :aria-label="`QR-код адреса ${issued.address.short}`"
                    data-test="appointment-qr"
                    v-html="issued.address.qr"
                />
            </div>

            <!-- На телефоне кнопки сверху вниз: «Новый код», «Закрыть», карточка. -->
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button ref="closeButton" variant="outline">Закрыть</Button>
                </DialogClose>
                <Button
                    v-if="expired || failed"
                    :aria-disabled="http.processing"
                    :aria-busy="http.processing"
                    data-test="appointment-new-pin"
                    @click="issue"
                >
                    <Spinner v-if="http.processing" />
                    <RefreshCw v-else />
                    Новый код
                </Button>
                <!-- В новой вкладке: окно с названным кодом остаётся открытым. -->
                <Button variant="ghost" as-child class="order-first sm:mr-auto">
                    <a
                        :href="card().url"
                        target="_blank"
                        rel="noopener"
                        data-test="appointment-card"
                    >
                        <Printer />
                        Карточка для стола
                        <span class="sr-only">(в новой вкладке)</span>
                    </a>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
