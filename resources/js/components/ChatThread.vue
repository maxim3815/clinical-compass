<script setup lang="ts">
import { HttpResponseError } from '@inertiajs/core';
import { useHttp, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onMounted,
    onUnmounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useRealtime } from '@/composables/useRealtime';
import type { Chat, ChatMessage, MessagesRead } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

// Как Message::MAX_LENGTH на сервере.
const MAX_LENGTH = 4000;

const props = defineProps<{
    chat: Chat;
    // Более ранние сообщения, отправка и прочтение для своей стороны диалога.
    routes: {
        messages: RouteDefinition<'get'>;
        store: RouteDefinition<'post'>;
        read: RouteDefinition<'post'>;
    };
    // Текст пустой ленты.
    empty: string;
    placeholder: string;
}>();

const page = usePage();
const me = computed(() => page.props.auth.user.id);

// У пациента текст не мельче 16 px, у врача на компьютере плотнее.
const isPatient = computed(() => page.props.auth.user.role === 'patient');

// Лента живёт на странице: новые сообщения приходят событиями, более ранние — по кнопке.
const messages = ref<ChatMessage[]>([...props.chat.messages]);
const hasEarlier = ref(props.chat.has_earlier);
const list = useTemplateRef<HTMLElement>('list');

// Ключ отправки: повтор после потерянного ответа не создаёт второе сообщение. Новый текст —
// новый ключ.
const form = useHttp<{ body: string; key: string }, ChatMessage>({
    body: '',
    key: '',
});
const earlier = useHttp<
    { before: number },
    { messages: ChatMessage[]; has_earlier: boolean }
>({ before: 0 });
const reading = useHttp<{ up_to: number }>({ up_to: 0 });

const tooLong = computed(() => form.body.length > MAX_LENGTH);

watch(
    () => form.body,
    () => {
        form.clearErrors('body');
        form.key = '';
    },
);

// На маленьком экране страница чата прокручивается, и ошибка под полем оказывается
// за нижней панелью: показываем её.
watch(
    () => form.errors.body,
    async (error) => {
        if (error) {
            await nextTick();
            document
                .getElementById('chat-body-error')
                ?.scrollIntoView({ block: 'nearest' });
        }
    },
);

onMounted(() => scrollToBottom());

useRealtime<ChatMessage>(`chat.${props.chat.id}`, 'message.sent', (message) => {
    const follow = message.sender_id === me.value || isNearBottom();

    add(message);

    if (message.sender_id !== me.value) {
        readWhenVisible(message.id);
    }

    if (follow) {
        void scrollToBottom();
    }
});

useRealtime<MessagesRead>(`chat.${props.chat.id}`, 'messages.read', (event) => {
    if (event.reader_id === me.value) {
        return;
    }

    for (const message of messages.value) {
        if (
            message.sender_id === me.value &&
            message.id <= event.last_read_id
        ) {
            message.read = true;
        }
    }
});

// Своё сообщение приходит и в ответе на отправку, и событием: второе не добавляется.
function add(message: ChatMessage): void {
    if (messages.value.some((item) => item.id === message.id)) {
        return;
    }

    const next = messages.value.findIndex((item) => item.id > message.id);

    if (next === -1) {
        messages.value.push(message);
    } else {
        messages.value.splice(next, 0, message);
    }
}

async function send(): Promise<void> {
    if (form.processing) {
        return;
    }

    if (form.key === '') {
        form.key = crypto.randomUUID();
    }

    // При ошибке проверки onSuccess не вызывается, текст ошибки уже под полем.
    try {
        await form.post(props.routes.store.url, {
            onSuccess: (message) => {
                add(message);
                // Только здесь: после onSuccess useHttp запоминает отправленный текст
                // как значение по умолчанию, и reset() вернул бы его в поле.
                form.reset();
                void scrollToBottom();
            },
        });
    } catch (error) {
        form.setError('body', sendError(error));
    }
}

function sendError(error: unknown): string {
    if (error instanceof HttpResponseError && error.response.status === 429) {
        return (JSON.parse(error.response.data) as { message: string }).message;
    }

    return 'Не удалось отправить сообщение. Проверьте соединение и попробуйте ещё раз.';
}

// Сообщение в скрытой вкладке ещё не прочитано: его отметят, когда вкладку откроют.
// Иначе собеседник не получил бы пуш о сообщении, которого не видел.
let unreadUpTo = 0;

function readWhenVisible(upTo: number): void {
    if (document.visibilityState === 'visible') {
        markRead(upTo);
    } else {
        unreadUpTo = Math.max(unreadUpTo, upTo);
    }
}

function readPending(): void {
    if (document.visibilityState === 'visible' && unreadUpTo > 0) {
        markRead(unreadUpTo);
        unreadUpTo = 0;
    }
}

onMounted(() => document.addEventListener('visibilitychange', readPending));
onUnmounted(() =>
    document.removeEventListener('visibilitychange', readPending),
);

// Фоновая отметка: если не дошла, сообщение станет прочитанным при следующем открытии диалога.
function markRead(upTo: number): void {
    reading.up_to = upTo;
    reading.post(props.routes.read.url).catch(() => {});
}

async function loadEarlier(): Promise<void> {
    if (!list.value || messages.value.length === 0) {
        return;
    }

    earlier.before = messages.value[0].id;
    const heightBefore = list.value.scrollHeight;

    try {
        const result = await earlier.get(props.routes.messages.url);
        result.messages.forEach(add);
        hasEarlier.value = result.has_earlier;
    } catch {
        toast.error('Не удалось загрузить сообщения. Попробуйте ещё раз.');

        return;
    }

    // Лента остаётся на том же сообщении, а более ранние появляются выше.
    await nextTick();
    list.value.scrollTop += list.value.scrollHeight - heightBefore;
}

function isNearBottom(): boolean {
    const el = list.value;

    return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 80;
}

async function scrollToBottom(): Promise<void> {
    await nextTick();

    if (list.value) {
        list.value.scrollTop = list.value.scrollHeight;
    }
}
</script>

<template>
    <!--
        Лента растягивается на всё место, которое страница отвела чату: страница чата
        высотой с экран, поэтому поле и кнопка «Отправить» видны без прокрутки.
        Новые сообщения скринридер читает сам (role="log"), а подгруженные ранние — нет:
        пока они грузятся, лента занята (aria-busy).
    -->
    <div
        class="flex min-h-0 flex-1 flex-col gap-3"
        :class="isPatient ? 'text-base' : 'text-sm'"
    >
        <div
            ref="list"
            class="flex min-h-40 flex-1 flex-col gap-2 overflow-y-auto rounded-2xl border bg-card p-3"
            role="log"
            aria-label="Переписка"
            :aria-busy="earlier.processing"
            data-test="chat-messages"
        >
            <Button
                v-if="hasEarlier"
                variant="ghost"
                :size="isPatient ? 'lg' : 'sm'"
                class="self-center"
                :disabled="earlier.processing"
                @click="loadEarlier"
            >
                Показать более ранние
            </Button>

            <p
                v-if="messages.length === 0"
                class="m-auto max-w-sm text-center text-muted-foreground"
            >
                {{ empty }}
            </p>

            <template v-for="(message, i) in messages" :key="message.id">
                <p
                    v-if="i === 0 || messages[i - 1].date !== message.date"
                    class="my-1 self-center text-muted-foreground"
                    :class="isPatient ? 'text-base' : 'text-sm'"
                >
                    {{ message.date }}
                </p>

                <div
                    class="flex max-w-[85%] flex-col gap-1"
                    :class="
                        message.sender_id === me
                            ? 'items-end self-end'
                            : 'items-start self-start'
                    "
                    data-test="chat-message"
                >
                    <p
                        class="rounded-2xl px-3 py-2 wrap-anywhere whitespace-pre-wrap"
                        :class="
                            message.sender_id === me
                                ? 'rounded-br-md bg-primary text-primary-foreground'
                                : 'rounded-bl-md bg-muted'
                        "
                    >
                        {{ message.body }}
                    </p>
                    <p
                        class="text-muted-foreground"
                        :class="isPatient ? 'text-base' : 'text-sm'"
                    >
                        {{ message.time }}
                        <template v-if="message.sender_id === me">
                            · {{ message.read ? 'Прочитано' : 'Отправлено' }}
                        </template>
                    </p>
                </div>
            </template>
        </div>

        <!--
            «Отправить» в одной строке с полем: когда на телефоне открыта клавиатура, браузер
            показывает поле, и кнопка рядом с ним тоже видна.
        -->
        <form class="flex flex-col gap-1" @submit.prevent="send">
            <div class="flex items-end gap-2">
                <Label for="chat-body" class="sr-only">Сообщение</Label>
                <Textarea
                    id="chat-body"
                    v-model="form.body"
                    name="body"
                    rows="1"
                    class="max-h-40 min-h-11 flex-1"
                    :placeholder="placeholder"
                    :aria-invalid="form.errors.body ? true : undefined"
                    aria-describedby="chat-body-error"
                    @keydown.enter.ctrl.exact.prevent="send"
                    @keydown.enter.meta.exact.prevent="send"
                />
                <Button
                    type="submit"
                    :size="isPatient ? 'lg' : 'default'"
                    :disabled="form.processing"
                    data-test="send-message"
                >
                    Отправить
                </Button>
            </div>
            <InputError id="chat-body-error" :message="form.errors.body" />

            <p
                v-if="tooLong"
                class="text-destructive"
                :class="isPatient ? 'text-base' : 'text-sm'"
            >
                {{ form.body.length }} из {{ MAX_LENGTH }} символов
            </p>
            <p
                v-else-if="!isPatient"
                class="hidden text-xs text-muted-foreground md:block"
            >
                Ctrl+Enter или ⌘+Enter — отправить
            </p>
        </form>
    </div>
</template>
