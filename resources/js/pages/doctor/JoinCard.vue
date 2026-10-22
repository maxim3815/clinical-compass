<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Printer } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { doctorLine } from '@/lib/doctor';
import { card, index } from '@/routes/doctor/invitations';
import type { InvitingDoctor, JoinAddress } from '@/types';

defineProps<{
    doctor: InvitingDoctor;
    address: JoinAddress;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Приглашения', href: index() },
            { title: 'Карточка для стола', href: card() },
        ],
    },
});

function print(): void {
    window.print();
}
</script>

<template>
    <Head title="Карточка для стола" />

    <div class="flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 flex-1 basis-80 flex-col gap-1">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Карточка для стола
                </h1>
                <p class="text-muted-foreground">
                    Распечатайте и поставьте на стол. Пациент наводит камеру на
                    QR-код и вводит код из окна «Пригласить на приёме». Без кода
                    по QR-коду подключиться нельзя.
                </p>
            </div>
            <Button data-test="print-card" @click="print">
                <Printer />
                Печать
            </Button>
        </header>

        <!-- На бумаге остаётся только карточка (print-sheet в app.css). -->
        <article
            class="print-sheet mx-auto flex w-full max-w-sm flex-col items-center gap-5 rounded-2xl border bg-white p-8 text-center shadow-xs"
            data-test="join-card"
        >
            <div class="flex items-center gap-3">
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-6" />
                </span>
                <span class="text-lg font-semibold">{{
                    $page.props.name
                }}</span>
            </div>
            <div class="flex flex-col gap-1">
                <h2 class="text-2xl font-semibold">Подключение к врачу</h2>
                <p class="text-lg">{{ doctorLine(doctor) }}</p>
            </div>
            <div
                class="size-60 [&>svg]:size-full"
                role="img"
                :aria-label="`QR-код адреса ${address.short}`"
                v-html="address.qr"
            />
            <ol class="flex flex-col gap-1 text-left text-lg">
                <li>1. Наведите камеру телефона на QR-код.</li>
                <li>2. Введите 4 цифры, которые назовёт врач.</li>
            </ol>
            <p class="text-muted-foreground">
                Без камеры — откройте адрес<br />
                <span class="font-medium break-words text-foreground">{{
                    address.short
                }}</span>
            </p>
        </article>
    </div>
</template>
