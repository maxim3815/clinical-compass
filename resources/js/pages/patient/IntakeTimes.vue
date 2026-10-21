<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import IntakeTimeController from '@/actions/App/Http/Controllers/Patient/IntakeTimeController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { home, medications } from '@/routes/patient';
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
    // Пациент пришёл с «Сегодня»: туда и вернётся.
    fromToday: boolean;
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: Props) => ({
        breadcrumbs: [
            props.fromToday
                ? { title: 'Сегодня', href: home() }
                : { title: 'Мои препараты', href: medications() },
            {
                title: 'Время приёма',
                href: IntakeTimeController.edit(props.prescription.id),
            },
        ],
    }),
});

const form = useForm<{ times: Record<string, string>; from: string | null }>({
    times: Object.fromEntries(
        props.prescription.intakes.map((intake) => [
            intake.time_of_day,
            intake.time,
        ]),
    ),
    from: props.fromToday ? 'today' : null,
});

const back = props.fromToday ? home() : medications();

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

    <div class="flex flex-col gap-6 p-4">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{
                    prescription.has_times ? 'Изменить время' : 'Выбрать время'
                }}
            </h1>
            <p class="text-lg">{{ prescription.title }}</p>
        </header>

        <form
            class="flex flex-col gap-6 rounded-2xl border bg-card p-4 shadow-xs"
            @submit.prevent="submit"
        >
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
                    class="h-11 w-40 text-lg"
                    required
                    :aria-invalid="
                        errors[`times.${intake.time_of_day}`] ? true : undefined
                    "
                    :aria-describedby="`time-${intake.time_of_day}-error`"
                />
                <InputError
                    :id="`time-${intake.time_of_day}-error`"
                    :message="errors[`times.${intake.time_of_day}`]"
                />
            </div>

            <InputError :message="form.errors.times" />

            <p class="text-muted-foreground">
                Время по вашему часовому поясу<template v-if="timezone"
                    >: {{ timezone }}</template
                >. Сменить пояс можно в
                <Link
                    :href="editProfile()"
                    class="rounded-sm font-medium text-primary underline underline-offset-4 outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                    >настройках профиля</Link
                >.
            </p>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button
                    type="submit"
                    size="lg"
                    :disabled="form.processing"
                    :aria-busy="form.processing"
                    data-test="save-intake-times"
                >
                    <Spinner v-if="form.processing" class="size-5" />
                    Сохранить
                </Button>
                <Button variant="outline" size="lg" as-child>
                    <Link :href="back">Отмена</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
