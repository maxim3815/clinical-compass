<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { OctagonAlert, TriangleAlert } from '@lucide/vue';
import AdherenceSignal from '@/components/AdherenceSignal.vue';
import ChatPatientContext from '@/components/ChatPatientContext.vue';
import ChatThread from '@/components/ChatThread.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/doctor/conversations';
import { show as card } from '@/routes/doctor/patients';
import { messages, read, show, store } from '@/routes/doctor/patients/chat';
import type { Chat, ChatPatientContext as Context } from '@/types';

type Patient = {
    id: number;
    name: string;
};

defineProps<{
    patient: Patient;
    chat: Chat;
    context: Context;
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

    <!-- Чат высотой с экран без шапки (h-14 и рамка). На широком экране справа — о пациенте. -->
    <div
        class="@container flex h-[calc(100svh-3.5rem-1px)] min-h-[28rem] w-full max-w-6xl gap-6 p-4 md:p-6"
    >
        <div class="flex min-w-0 flex-1 flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ patient.name }}
                </h1>
                <Button
                    variant="outline"
                    size="sm"
                    class="@4xl:hidden"
                    as-child
                >
                    <Link :href="card(patient.id)">Карточка пациента</Link>
                </Button>
            </div>

            <!-- На узком экране от панели остаются диагноз и то, что требует внимания. -->
            <p
                v-if="
                    context.diagnosis ||
                    context.contraindications.length > 0 ||
                    context.adherence.raised
                "
                class="flex flex-wrap items-center gap-2 text-sm @4xl:hidden"
                data-test="chat-patient-summary"
            >
                <span v-if="context.diagnosis" class="text-muted-foreground"
                    >{{ context.diagnosis.code }}
                    {{ context.diagnosis.name }}</span
                >
                <span
                    v-if="context.contraindications.length > 0"
                    class="inline-flex items-center gap-1 rounded-md border border-danger-border bg-danger-subtle px-1.5 py-0.5 font-medium text-danger"
                >
                    <OctagonAlert class="size-3.5 shrink-0" />
                    Противопоказание без решения
                </span>
                <span
                    v-if="context.adherence.raised"
                    class="inline-flex items-center gap-1"
                >
                    <TriangleAlert class="size-3.5 shrink-0 text-warning" />
                    <AdherenceSignal :signal="context.adherence" card />
                </span>
            </p>

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

        <aside
            class="hidden w-80 shrink-0 flex-col overflow-y-auto @4xl:flex"
            aria-label="О пациенте"
        >
            <ChatPatientContext
                :context="context"
                :card-href="card(patient.id)"
            />
        </aside>
    </div>
</template>
