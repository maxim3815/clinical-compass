<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ListPagination from '@/components/ListPagination.vue';
import { useRealtime } from '@/composables/useRealtime';
import { index } from '@/routes/doctor/conversations';
import { show } from '@/routes/doctor/patients/chat';
import type { Paginated } from '@/types';

type Conversation = {
    patient_id: number;
    name: string;
    last_message: string;
    last_message_mine: boolean;
    // «14:05» сегодня, «18.10.2026» раньше.
    last_message_at: string;
    unread_count: number;
};

defineProps<{
    conversations: Paginated<Conversation>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Сообщения',
                href: index(),
            },
        ],
    },
});

const page = usePage();

// Инициалы для кружка: фамилия и имя.
function initials(name: string): string {
    return name
        .split(' ')
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('');
}

// Пришло сообщение или диалог прочитан в другой вкладке: порядок и счётчики изменились.
useRealtime(
    `App.Models.User.${page.props.auth.user.id}`,
    'unread.changed',
    () => router.reload({ only: ['conversations'] }),
);
</script>

<template>
    <Head title="Сообщения" />

    <div class="flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Сообщения</h1>
            <p class="text-muted-foreground">
                Переписка с пациентами. Сначала диалоги с непрочитанными
                сообщениями.
            </p>
        </header>

        <p
            v-if="conversations.total === 0"
            class="rounded-xl border border-dashed bg-card p-6 text-muted-foreground"
            data-test="conversations-empty"
        >
            Сообщений пока нет. Написать пациенту можно из его карточки.
        </p>

        <ul
            v-else
            class="flex flex-col divide-y rounded-xl border bg-card shadow-xs"
        >
            <li
                v-for="conversation in conversations.data"
                :key="conversation.patient_id"
                data-test="conversation"
            >
                <Link
                    :href="show(conversation.patient_id)"
                    class="flex items-start gap-3 p-4 transition-colors outline-none first:rounded-t-xl last:rounded-b-xl hover:bg-accent/50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                >
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-full bg-secondary text-sm font-semibold text-secondary-foreground"
                        aria-hidden="true"
                        >{{ initials(conversation.name) }}</span
                    >
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <div class="flex items-baseline justify-between gap-2">
                            <span
                                class="truncate"
                                :class="
                                    conversation.unread_count > 0
                                        ? 'font-semibold'
                                        : 'font-medium'
                                "
                            >
                                {{ conversation.name }}
                            </span>
                            <span
                                class="shrink-0 text-sm text-muted-foreground tabular-nums"
                            >
                                {{ conversation.last_message_at }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <p
                                class="truncate text-sm"
                                :class="
                                    conversation.unread_count > 0
                                        ? 'text-foreground'
                                        : 'text-muted-foreground'
                                "
                            >
                                <template v-if="conversation.last_message_mine"
                                    >Вы:
                                </template>
                                {{ conversation.last_message }}
                            </p>
                            <span
                                v-if="conversation.unread_count > 0"
                                class="flex h-6 min-w-6 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-sm font-semibold text-primary-foreground tabular-nums"
                                data-test="unread-count"
                            >
                                <span class="sr-only">Непрочитанных:</span>
                                {{ conversation.unread_count }}
                            </span>
                        </div>
                    </div>
                </Link>
            </li>
        </ul>

        <ListPagination :page="conversations" />
    </div>
</template>
