<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import { computed } from 'vue';
import PasswordResetLinkController from '@/actions/App/Http/Controllers/Doctor/PasswordResetLinkController';
import Heading from '@/components/Heading.vue';
import OneTimeLink from '@/components/OneTimeLink.vue';
import { Button } from '@/components/ui/button';
import { formatAge } from '@/lib/plural';
import { index, show } from '@/routes/doctor/patients';

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
const passwordResetUrl = computed(() => page.flash.passwordResetUrl);

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
