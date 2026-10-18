<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

type PatientProfile = {
    birth_date: string | null;
    timezone: string;
};

defineProps<{
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
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">ФИО</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Фамилия Имя Отчество"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="login">Логин</Label>
                <Input
                    id="login"
                    class="mt-1 block w-full"
                    :default-value="user.login"
                    disabled
                />
                <p class="text-sm text-muted-foreground">
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
                        class="mt-1 block w-full"
                        name="birth_date"
                        type="date"
                        :default-value="patientProfile.birth_date ?? ''"
                        autocomplete="bday"
                    />
                    <InputError class="mt-2" :message="errors.birth_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="timezone">Часовой пояс</Label>
                    <select
                        id="timezone"
                        name="timezone"
                        class="mt-1 h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                        required
                    >
                        <option
                            v-for="timezone in timezones"
                            :key="timezone.value"
                            :value="timezone.value"
                            :selected="
                                timezone.value === patientProfile.timezone
                            "
                        >
                            {{ timezone.label }}
                        </option>
                    </select>
                    <p class="text-sm text-muted-foreground">
                        По нему будут приходить напоминания о приёме препаратов.
                    </p>
                    <InputError class="mt-2" :message="errors.timezone" />
                </div>
            </template>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Сохранить</Button
                >
            </div>
        </Form>
    </div>
</template>
