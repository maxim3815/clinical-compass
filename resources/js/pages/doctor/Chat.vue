<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ChatThread from '@/components/ChatThread.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/doctor/conversations';
import { show as card } from '@/routes/doctor/patients';
import { messages, read, show, store } from '@/routes/doctor/patients/chat';
import type { Chat } from '@/types';

type Patient = {
    id: number;
    name: string;
};

defineProps<{
    patient: Patient;
    chat: Chat;
}>();

defineOptions({
    layout: (props: { patient: Patient }) => ({
        breadcrumbs: [
            {
                title: 'Сообщения',
                href: index(),
            },
            {
                title: props.patient.name,
                href: show(props.patient.id),
            },
        ],
    }),
});
</script>

<template>
    <Head :title="`Чат: ${patient.name}`" />

    <div class="flex max-w-2xl flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold tracking-tight">
                {{ patient.name }}
            </h1>
            <Button variant="outline" size="sm" as-child>
                <Link :href="card(patient.id)">Карточка пациента</Link>
            </Button>
        </div>

        <ChatThread
            :chat="chat"
            :routes="{
                messages: messages(patient.id),
                store: store(patient.id),
                read: read(patient.id),
            }"
            empty="Сообщений пока нет."
            placeholder="Сообщение пациенту"
        />
    </div>
</template>
