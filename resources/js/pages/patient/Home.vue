<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Clock, Stethoscope } from '@lucide/vue';
import { computed, ref } from 'vue';
import IntakeTimeController from '@/actions/App/Http/Controllers/Patient/IntakeTimeController';
import AsNeededCard from '@/components/AsNeededCard.vue';
import DoseGroupCard from '@/components/DoseGroupCard.vue';
import DoseMarkDialog from '@/components/DoseMarkDialog.vue';
import InstallAppBanner from '@/components/InstallAppBanner.vue';
import { Button } from '@/components/ui/button';
import { home } from '@/routes/patient';
import type { AsNeeded, Dose, DoseGroup, SelectOption } from '@/types';

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
    hasPrescriptions: boolean;
    today: {
        date: string;
        groups: DoseGroup[];
    };
    yesterday: {
        groups: DoseGroup[];
    };
    asNeeded: AsNeeded[];
    skipReasons: SelectOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Сегодня',
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

// Приём, который пациент отмечает в диалоге, и с какой отметки диалог открыт.
const markedDose = ref<Dose | null>(null);
const markStatus = ref<'taken' | 'skipped'>('taken');

function openMark(dose: Dose, status: 'taken' | 'skipped'): void {
    markStatus.value = status;
    markedDose.value = dose;
}
</script>

<template>
    <Head title="Сегодня" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">Здравствуйте, {{ greetingName }}!</h1>

        <InstallAppBanner />

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

        <section class="flex flex-col gap-3" data-test="today">
            <h2 class="font-semibold">Сегодня, {{ today.date }}</h2>

            <p
                v-if="!hasPrescriptions"
                class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
                data-test="no-prescriptions"
            >
                Врач пока не назначил препараты.
            </p>
            <p
                v-else-if="today.groups.length === 0"
                class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
            >
                На сегодня приёмов по расписанию нет.
            </p>

            <DoseGroupCard
                v-for="group in today.groups"
                :key="group.time"
                :group="group"
                @mark="openMark"
            />
        </section>

        <section
            v-if="yesterday.groups.length"
            class="flex flex-col gap-3"
            data-test="yesterday"
        >
            <div>
                <h2 class="font-semibold">Вчера</h2>
                <p class="text-sm text-muted-foreground">
                    Приёмы без отметки. Если вы принимали препарат, отметьте
                    это.
                </p>
            </div>

            <DoseGroupCard
                v-for="group in yesterday.groups"
                :key="group.time"
                :group="group"
                @mark="openMark"
            />
        </section>

        <section
            v-if="asNeeded.length"
            class="flex flex-col gap-3"
            data-test="as-needed-list"
        >
            <h2 class="font-semibold">По необходимости</h2>

            <AsNeededCard
                v-for="prescription in asNeeded"
                :key="prescription.id"
                :prescription="prescription"
            />
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

        <DoseMarkDialog
            v-model:dose="markedDose"
            v-model:status="markStatus"
            :reasons="skipReasons"
        />
    </div>
</template>
