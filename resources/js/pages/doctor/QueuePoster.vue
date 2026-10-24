<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Printer } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { doctorLine } from '@/lib/doctor';
import { index } from '@/routes/doctor/patients';
import { poster } from '@/routes/doctor/queue';
import type { InvitingDoctor, JoinAddress } from '@/types';

/**
 * Плакат для двери кабинета терапевта: пациенты в очереди наводят камеру на QR-код и отвечают
 * на вопросы о самочувствии без регистрации. Печатается на A4 через браузер [медик].
 */
defineProps<{
    doctor: InvitingDoctor;
    address: JoinAddress;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Пациенты', href: index() },
            { title: 'Плакат для двери', href: poster() },
        ],
    },
});

function print(): void {
    window.print();
}
</script>

<template>
    <Head title="Плакат для двери" />

    <div class="flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 flex-1 basis-80 flex-col gap-1">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Плакат для двери
                </h1>
                <p class="text-muted-foreground">
                    Распечатайте и повесьте у двери кабинета. Пациент в очереди
                    наводит камеру на QR-код, отвечает на вопросы о самочувствии
                    и получает код из 4 цифр. Ответы появятся у вас в «Ответили
                    сегодня», откройте их по коду, который назовёт пациент.
                    Номер кабинета впишите от руки.
                </p>
            </div>
            <Button data-test="print-poster" @click="print">
                <Printer />
                Печать
            </Button>
        </header>

        <!--
            На бумаге остаётся только плакат (print-sheet в app.css): на весь лист A4, крупно, чтобы
            прочитать с лавки в очереди и навести камеру издалека.
        -->
        <article
            class="print-sheet mx-auto flex w-full max-w-xl flex-col items-center gap-6 rounded-2xl border bg-white p-10 text-center shadow-xs print:h-screen print:max-w-none print:justify-center print:gap-5 print:rounded-none print:border-0 print:p-0"
            data-test="queue-poster"
        >
            <div class="flex items-center gap-3">
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-6" />
                </span>
                <span class="text-lg font-semibold print:text-2xl">{{
                    $page.props.name
                }}</span>
            </div>
            <div class="flex flex-col gap-2">
                <h2
                    class="text-4xl font-semibold tracking-tight print:text-6xl"
                >
                    Ждёте приёма?
                </h2>
                <p class="text-2xl print:text-4xl">
                    Ответьте на вопросы о самочувствии, пока ждёте
                </p>
            </div>
            <!-- Чей это QR: у соседней двери может висеть такой же плакат. -->
            <div class="flex flex-col gap-1 text-xl print:text-3xl">
                <p class="font-semibold">{{ doctorLine(doctor) }}</p>
                <p>Кабинет ________</p>
            </div>
            <div
                class="size-72 print:size-[10cm] [&>svg]:size-full"
                role="img"
                :aria-label="`QR-код адреса ${address.short}`"
                v-html="address.qr"
            />
            <ol class="flex flex-col gap-1 text-left text-xl print:text-3xl">
                <li>1. Наведите камеру телефона на QR-код.</li>
                <li>2. Ответьте на вопросы, это 3–5 минут.</li>
                <li>3. Назовите полученный код на приёме.</li>
            </ol>
            <p class="text-lg print:text-2xl">
                Без регистрации, имя не спрашиваем. Ответы увидит только этот
                врач.
            </p>
            <p class="text-muted-foreground print:text-xl">
                Если камеры нет, откройте адрес<br />
                <span
                    class="text-lg font-medium break-words text-foreground print:text-2xl"
                    >{{ address.short }}</span
                >
            </p>
        </article>
    </div>
</template>
