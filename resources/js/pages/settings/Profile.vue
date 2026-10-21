<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
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

    <h1 class="sr-only">Профиль</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Профиль"
            :description="
                patientProfile
                    ? 'ФИО, дата рождения и часовой пояс'
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
    </div>
</template>
