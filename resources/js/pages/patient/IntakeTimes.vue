<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import IntakeTimeController from '@/actions/App/Http/Controllers/Patient/IntakeTimeController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { medications } from '@/routes/patient';
import { edit as editProfile } from '@/routes/profile';

type Intake = {
    time_of_day: string;
    label: string;
    dose: string;
    time: string;
};

type Props = {
    prescription: {
        id: number;
        title: string;
        intakes: Intake[];
        has_times: boolean;
    };
    timezone: string | null;
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: Props) => ({
        breadcrumbs: [
            {
                title: 'Мои препараты',
                href: medications(),
            },
            {
                title: 'Время приёма',
                href: IntakeTimeController.edit(props.prescription.id),
            },
        ],
    }),
});

const form = useForm<{ times: Record<string, string> }>({
    times: Object.fromEntries(
        props.prescription.intakes.map((intake) => [
            intake.time_of_day,
            intake.time,
        ]),
    ),
});

// Ошибки приходят с ключами вида «times.morning».
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

// Ошибка о совпадающем времени стоит у обоих приёмов: после правки любого из них сбрасываем все.
watch(
    () => ({ ...form.times }),
    () => form.clearErrors(),
);

function submit(): void {
    form.submit(IntakeTimeController.update(props.prescription.id));
}
</script>

<template>
    <Head title="Время приёма" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            :title="prescription.has_times ? 'Изменить время' : 'Выбрать время'"
            :description="prescription.title"
        />

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div
                v-for="intake in prescription.intakes"
                :key="intake.time_of_day"
                class="grid gap-2"
                data-test="intake-time"
            >
                <Label :for="`time-${intake.time_of_day}`">
                    {{ intake.label }}: {{ intake.dose }}
                </Label>
                <Input
                    :id="`time-${intake.time_of_day}`"
                    v-model="form.times[intake.time_of_day]"
                    :name="`times.${intake.time_of_day}`"
                    type="time"
                    class="w-40"
                    required
                />
                <InputError :message="errors[`times.${intake.time_of_day}`]" />
            </div>

            <InputError :message="form.errors.times" />

            <p class="text-sm text-muted-foreground">
                Время по вашему часовому поясу<template v-if="timezone"
                    >: {{ timezone }}</template
                >. Сменить пояс можно в
                <Link :href="editProfile()" class="underline underline-offset-4"
                    >настройках профиля</Link
                >.
            </p>

            <div class="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="save-intake-times"
                >
                    Сохранить
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="medications()">Отмена</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
