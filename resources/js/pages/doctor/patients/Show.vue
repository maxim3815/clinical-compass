<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { KeyRound, MessageCircle, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import PasswordResetLinkController from '@/actions/App/Http/Controllers/Doctor/PasswordResetLinkController';
import PrescriptionController from '@/actions/App/Http/Controllers/Doctor/PrescriptionController';
import AdherenceCourse from '@/components/AdherenceCourse.vue';
import CancelPrescriptionDialog from '@/components/CancelPrescriptionDialog.vue';
import Heading from '@/components/Heading.vue';
import OneTimeLink from '@/components/OneTimeLink.vue';
import PrescriptionDetails from '@/components/PrescriptionDetails.vue';
import { Button } from '@/components/ui/button';
import { formatAge } from '@/lib/plural';
import { index, show } from '@/routes/doctor/patients';
import { show as chat } from '@/routes/doctor/patients/chat';
import type { Adherence, Prescription, SelectOption } from '@/types';

type Patient = {
    id: number;
    name: string;
    login: string;
    birth_date: string | null;
    age: number | null;
    started_at: string;
    timezone: string | null;
};

const props = defineProps<{
    patient: Patient;
    prescriptions: Prescription[];
    ownMedications: Prescription[];
    prescriptionHistory: Prescription[];
    adherence: Adherence;
    cancelReasons: SelectOption[];
}>();

defineOptions({
    layout: (props: { patient: Patient }) => ({
        breadcrumbs: [
            {
                title: 'Пациенты',
                href: index(),
            },
            {
                title: props.patient.name,
                href: show(props.patient.id),
            },
        ],
    }),
});

const page = usePage();
const cancelling = ref<Prescription | null>(null);
const passwordResetUrl = computed(() => page.flash.passwordResetUrl);

const hasCourses = computed(
    () =>
        props.adherence.courses.length > 0 ||
        props.adherence.finished.length > 0 ||
        props.adherence.own.length > 0,
);

const details = computed(() => [
    {
        label: 'Дата рождения',
        value:
            props.patient.birth_date === null
                ? 'не указана'
                : props.patient.age === null
                  ? props.patient.birth_date
                  : `${props.patient.birth_date} (${formatAge(props.patient.age)})`,
    },
    { label: 'Логин', value: props.patient.login },
    { label: 'Под наблюдением с', value: props.patient.started_at },
    { label: 'Часовой пояс', value: props.patient.timezone ?? '—' },
]);
</script>

<template>
    <Head :title="patient.name" />

    <div class="flex max-w-3xl flex-col gap-8 p-4">
        <Heading :title="patient.name" description="Карточка пациента" />

        <dl
            class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[max-content_1fr]"
            data-test="patient-details"
        >
            <template v-for="item in details" :key="item.label">
                <dt class="text-muted-foreground">{{ item.label }}</dt>
                <dd class="-mt-2 font-medium sm:mt-0">{{ item.value }}</dd>
            </template>
        </dl>

        <Button variant="outline" class="self-start" as-child>
            <Link :href="chat(patient.id)" data-test="chat-button">
                <MessageCircle />
                Написать
            </Link>
        </Button>

        <section class="flex flex-col gap-3" data-test="prescriptions">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Heading variant="small" title="Назначения" />
                <Button size="sm" as-child>
                    <Link
                        :href="PrescriptionController.create(patient.id)"
                        data-test="prescribe-button"
                    >
                        <Plus />
                        Назначить
                    </Link>
                </Button>
            </div>

            <p
                v-if="prescriptions.length === 0"
                class="text-sm text-muted-foreground"
            >
                Назначений пока нет.
            </p>

            <PrescriptionDetails
                v-for="prescription in prescriptions"
                :key="prescription.id"
                :prescription="prescription"
                for-doctor
            >
                <template v-if="prescription.can_change" #actions>
                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="
                                PrescriptionController.edit({
                                    patient: patient.id,
                                    prescription: prescription.id,
                                })
                            "
                        >
                            Изменить
                        </Link>
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        data-test="cancel-prescription-button"
                        @click="cancelling = prescription"
                    >
                        Отменить
                    </Button>
                </template>
            </PrescriptionDetails>
        </section>

        <section
            v-if="ownMedications.length > 0"
            class="flex flex-col gap-3"
            data-test="own-medications"
        >
            <Heading
                variant="small"
                title="Добавил пациент"
                description="Препараты от других врачей, которые пациент добавил сам. Изменить их может только пациент."
            />

            <PrescriptionDetails
                v-for="prescription in ownMedications"
                :key="prescription.id"
                :prescription="prescription"
                for-doctor
            />
        </section>

        <section class="flex flex-col gap-3" data-test="adherence">
            <Heading
                variant="small"
                title="Приём препаратов"
                description="Доля принятых приёмов по контрольным периодам курса: дни 1–7, 8–14, 15–28, дальше каждые 28 дней."
            />

            <p
                v-if="adherence.timezone"
                class="text-sm text-muted-foreground"
                data-test="adherence-timezone"
            >
                Время пациента: {{ adherence.timezone }}
            </p>

            <p v-if="!hasCourses" class="text-sm text-muted-foreground">
                Пациент пока не принимает препараты по назначению.
            </p>

            <AdherenceCourse
                v-for="course in adherence.courses"
                :key="course.id"
                :course="course"
            />

            <template v-if="adherence.finished.length > 0">
                <h3 class="mt-2 text-sm font-medium text-muted-foreground">
                    Завершённые курсы
                </h3>
                <AdherenceCourse
                    v-for="course in adherence.finished"
                    :key="course.id"
                    :course="course"
                />
            </template>

            <AdherenceCourse
                v-for="course in adherence.own"
                :key="course.id"
                :course="course"
            />
        </section>

        <section
            v-if="prescriptionHistory.length > 0"
            class="flex flex-col gap-3"
            data-test="prescription-history"
        >
            <Heading
                variant="small"
                title="История назначений"
                description="Прежние версии изменённых назначений, отменённые и завершённые назначения."
            />

            <PrescriptionDetails
                v-for="prescription in prescriptionHistory"
                :key="prescription.id"
                :prescription="prescription"
                for-doctor
            />
        </section>

        <CancelPrescriptionDialog
            v-model:prescription="cancelling"
            :patient-id="patient.id"
            :reasons="cancelReasons"
        />

        <section class="flex flex-col gap-3">
            <Heading
                variant="small"
                title="Забыл пароль"
                description="Пациент откроет ссылку и сам задаст новый пароль. Ссылка действует 24 часа и срабатывает один раз, новая ссылка отменяет прежнюю."
            />

            <Form
                v-bind="PasswordResetLinkController.store.form(patient.id)"
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
            >
                <Button
                    variant="outline"
                    :disabled="processing"
                    data-test="password-reset-link-button"
                >
                    <KeyRound />
                    Ссылка для смены пароля
                </Button>
            </Form>

            <OneTimeLink
                v-if="passwordResetUrl"
                :url="passwordResetUrl"
                label="Ссылка для смены пароля"
                data-test="password-reset-link"
            >
                Отправьте ссылку пациенту. Больше она не покажется: если
                потеряете, создайте новую.
            </OneTimeLink>
        </section>
    </div>
</template>
