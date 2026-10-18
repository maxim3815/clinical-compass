<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Stethoscope } from '@lucide/vue';
import { computed } from 'vue';
import { home } from '@/routes/patient';

type Doctor = {
    name: string;
    specialty: string | null;
};

defineProps<{
    doctor: Doctor | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Главная',
                href: home(),
            },
        ],
    },
});

const page = usePage();

// ФИО хранится одной строкой «Фамилия Имя Отчество»: обращаемся по имени и отчеству.
const greetingName = computed(() => {
    const parts = page.props.auth.user.name.trim().split(/\s+/);

    return parts.length > 1 ? parts.slice(1).join(' ') : parts[0];
});
</script>

<template>
    <Head title="Главная" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">Здравствуйте, {{ greetingName }}!</h1>

        <section
            v-if="doctor"
            class="flex items-start gap-4 rounded-lg border p-4"
            data-test="current-doctor"
        >
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted"
            >
                <Stethoscope class="size-5 text-muted-foreground" />
            </div>
            <div class="flex min-w-0 flex-col gap-1">
                <h2 class="text-sm text-muted-foreground">Ваш врач</h2>
                <p class="font-medium break-words">{{ doctor.name }}</p>
                <p
                    v-if="doctor.specialty"
                    class="text-sm text-muted-foreground"
                >
                    {{ doctor.specialty }}
                </p>
            </div>
        </section>

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
