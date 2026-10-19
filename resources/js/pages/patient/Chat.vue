<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Phone } from '@lucide/vue';
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

    <div class="flex max-w-2xl flex-col gap-4 p-4">
        <div
            class="flex items-start gap-3 rounded-lg border border-amber-500/50 bg-amber-50 p-3 text-sm dark:bg-amber-950/30"
            role="note"
            data-test="emergency-note"
        >
            <Phone class="mt-0.5 size-4 shrink-0" />
            <p>
                Чат не для экстренной помощи. Врач может ответить не сразу. Если
                состояние резко ухудшилось, звоните 112.
            </p>
        </div>

        <template v-if="chat">
            <p class="text-sm text-muted-foreground">
                Ваш врач:
                <span class="font-medium text-foreground">{{
                    chat.doctor.name
                }}</span
                ><template v-if="chat.doctor.specialty"
                    >, {{ chat.doctor.specialty.toLowerCase() }}</template
                >
            </p>

            <ChatThread
                :chat="chat"
                :routes="{ messages: messages(), store: store(), read: read() }"
                empty="Сообщений пока нет. Напишите врачу, если хотите что-то уточнить о лечении."
                placeholder="Сообщение врачу"
            />
        </template>

        <p
            v-else
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
            data-test="no-doctor"
        >
            Вы сейчас не прикреплены к врачу. Обратитесь к своему врачу за новым
            приглашением.
        </p>
    </div>
</template>
