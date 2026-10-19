<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
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

// Пришло сообщение или диалог прочитан в другой вкладке: порядок и счётчики изменились.
useRealtime(
    `App.Models.User.${page.props.auth.user.id}`,
    'unread.changed',
    () => router.reload({ only: ['conversations'] }),
);
</script>

<template>
    <Head title="Сообщения" />

    <div class="flex max-w-3xl flex-col gap-6 p-4">
        <Heading
            title="Сообщения"
            description="Переписка с пациентами. Сначала диалоги с непрочитанными сообщениями."
        />

        <p
            v-if="conversations.total === 0"
            class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
            data-test="conversations-empty"
        >
            Сообщений пока нет. Написать пациенту можно из его карточки.
        </p>

        <ul v-else class="flex flex-col divide-y rounded-lg border">
            <li
                v-for="conversation in conversations.data"
                :key="conversation.patient_id"
                data-test="conversation"
            >
                <Link
                    :href="show(conversation.patient_id)"
                    class="flex items-start gap-3 px-3 py-3 hover:bg-muted/50"
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
                                class="shrink-0 text-xs text-muted-foreground"
                            >
                                {{ conversation.last_message_at }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <p class="truncate text-sm text-muted-foreground">
                                <template v-if="conversation.last_message_mine"
                                    >Вы:
                                </template>
                                {{ conversation.last_message }}
                            </p>
                            <span
                                v-if="conversation.unread_count > 0"
                                class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1 text-xs font-medium text-primary-foreground tabular-nums"
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
