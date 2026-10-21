<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Phone, Stethoscope, UserRoundX } from '@lucide/vue';
import ChatThread from '@/components/ChatThread.vue';
import { messages, read, show, store } from '@/routes/patient/chat';
import type { Chat } from '@/types';

defineProps<{
    // Без лечащего врача диалога нет.
    chat:
        | (Chat & {
              doctor: {
                  name: string;
                  specialty: string | null;
              };
          })
        | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Чат с врачом',
                href: show(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Чат с врачом" />

    <!--
        Чат высотой с экран без шапки и панели вкладок (--patient-chrome в PatientLayout).
        Если на узком экране или с крупным шрифтом всё не помещается, прокручивается сам чат,
        и поле с кнопкой «Отправить» не уходят под панель.
    -->
    <div
        class="flex h-[calc(100svh-var(--patient-chrome))] flex-col gap-3 overflow-y-auto p-4"
    >
        <h1 class="sr-only">Чат с врачом</h1>

        <div
            v-if="chat"
            class="flex items-center gap-3"
            data-test="chat-doctor"
        >
            <div
                class="flex size-11 shrink-0 items-center justify-center rounded-full bg-secondary text-secondary-foreground"
            >
                <Stethoscope class="size-6" />
            </div>
            <div class="flex min-w-0 flex-col">
                <p class="font-semibold break-words">{{ chat.doctor.name }}</p>
                <p v-if="chat.doctor.specialty" class="text-muted-foreground">
                    {{ chat.doctor.specialty }}
                </p>
            </div>
        </div>

        <div
            class="flex items-start gap-3 rounded-xl border border-warning-border bg-warning-subtle p-3"
            role="note"
            data-test="emergency-note"
        >
            <Phone class="mt-0.5 size-5 shrink-0 text-warning" />
            <p>
                Чат не для экстренной помощи. Врач может ответить не сразу. Если
                состояние резко ухудшилось, звоните
                <a
                    href="tel:112"
                    class="rounded-sm font-semibold underline underline-offset-4 outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                    >112</a
                >.
            </p>
        </div>

        <ChatThread
            v-if="chat"
            :chat="chat"
            :routes="{ messages: messages(), store: store(), read: read() }"
            empty="Сообщений пока нет. Напишите врачу, если хотите что-то уточнить о лечении."
            placeholder="Сообщение врачу"
        />

        <div
            v-else
            class="flex items-start gap-4 rounded-2xl border border-dashed p-4"
            data-test="no-doctor"
        >
            <UserRoundX class="mt-0.5 size-6 shrink-0 text-muted-foreground" />
            <p>
                Вы сейчас не прикреплены к врачу. Обратитесь к своему врачу за
                новым приглашением.
            </p>
        </div>
    </div>
</template>
