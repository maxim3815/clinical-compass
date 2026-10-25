<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { FileText, Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { consent } from '@/routes/patient';
import { show as rules } from '@/routes/patient/rules';
import { edit } from '@/routes/profile';

type PatientProfile = {
    birth_date: string | null;
    timezone: string;
};

const props = defineProps<{
    patientProfile: PatientProfile | null;
    timezones: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Профиль',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const timezone = ref(props.patientProfile?.timezone ?? '');
</script>

<template>
    <Head title="Профиль" />

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Профиль"
            :description="
                patientProfile
                    ? 'ФИО, дата рождения, часовой пояс, правила и согласие'
                    : 'ФИО и логин'
            "
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            novalidate
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">ФИО</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Фамилия Имя Отчество"
                    :aria-invalid="errors.name ? true : undefined"
                    aria-describedby="name-error"
                />
                <InputError id="name-error" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="login">Логин</Label>
                <Input
                    id="login"
                    :default-value="user.login"
                    disabled
                    aria-describedby="login-hint"
                />
                <p id="login-hint" class="text-base text-muted-foreground">
                    Логин изменить нельзя.
                </p>
            </div>

            <template v-if="patientProfile">
                <div class="grid gap-2">
                    <Label for="birth_date"
                        >Дата рождения (необязательно)</Label
                    >
                    <Input
                        id="birth_date"
                        name="birth_date"
                        type="date"
                        :default-value="patientProfile.birth_date ?? ''"
                        autocomplete="bday"
                        :aria-invalid="errors.birth_date ? true : undefined"
                        aria-describedby="birth_date-error"
                    />
                    <InputError
                        id="birth_date-error"
                        :message="errors.birth_date"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="timezone">Часовой пояс</Label>
                    <NativeSelect
                        id="timezone"
                        name="timezone"
                        v-model="timezone"
                        required
                        :aria-invalid="errors.timezone ? true : undefined"
                        aria-describedby="timezone-hint timezone-error"
                    >
                        <option
                            v-for="option in timezones"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </NativeSelect>
                    <p
                        id="timezone-hint"
                        class="text-base text-muted-foreground"
                    >
                        По нему будут приходить напоминания о приёме препаратов.
                    </p>
                    <InputError
                        id="timezone-error"
                        :message="errors.timezone"
                    />
                </div>
            </template>

            <Button
                size="lg"
                class="w-full sm:w-auto"
                :disabled="processing"
                data-test="update-profile-button"
            >
                Сохранить
            </Button>
        </Form>

        <!-- Тексты, с которыми пациент согласился при регистрации: перечитать, не выходя из кабинета. -->
        <section
            v-if="patientProfile"
            class="flex flex-col gap-2 border-t pt-6"
            aria-labelledby="documents-title"
            data-test="patient-documents"
        >
            <h2 id="documents-title" class="text-lg font-semibold">
                Правила и согласие
            </h2>
            <ul class="flex flex-col gap-1">
                <li>
                    <Link
                        :href="rules()"
                        class="inline-flex min-h-11 items-center gap-2 text-base font-medium text-primary underline-offset-4 outline-none hover:underline focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-solid"
                    >
                        <Info class="size-5 shrink-0" />
                        Как работает помощь в приложении
                    </Link>
                </li>
                <li>
                    <Link
                        :href="consent()"
                        class="inline-flex min-h-11 items-center gap-2 text-base font-medium text-primary underline-offset-4 outline-none hover:underline focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-solid"
                    >
                        <FileText class="size-5 shrink-0" />
                        Согласие на обработку персональных данных
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
