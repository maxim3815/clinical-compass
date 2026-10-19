<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Clock, Stethoscope } from '@lucide/vue';
import { computed } from 'vue';
import IntakeTimeController from '@/actions/App/Http/Controllers/Patient/IntakeTimeController';
import { Button } from '@/components/ui/button';
import { home } from '@/routes/patient';

type Doctor = {
    name: string;
    specialty: string | null;
};

// Назначение, у которого есть приём без выбранного времени.
type TimeSetup = {
    id: number;
    title: string;
};

defineProps<{
    doctor: Doctor | null;
    timeSetup: TimeSetup[];
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
            v-for="prescription in timeSetup"
            :key="prescription.id"
            class="flex items-start gap-4 rounded-lg border border-primary/40 p-4"
            data-test="time-setup"
        >
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted"
            >
                <Clock class="size-5 text-muted-foreground" />
            </div>
            <div class="flex min-w-0 flex-col items-start gap-2">
                <h2 class="font-medium">Настройте время приёма</h2>
                <p class="text-sm break-words">
                    {{ prescription.title }}: выберите, во сколько вы будете
                    принимать препарат.
                </p>
                <Button size="sm" as-child>
                    <Link :href="IntakeTimeController.edit(prescription.id)">
                        Выбрать время
                    </Link>
                </Button>
            </div>
        </section>

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
