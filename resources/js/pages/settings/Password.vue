<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import PasswordController from '@/actions/App/Http/Controllers/Settings/PasswordController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/password';

defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Пароль',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Пароль" />

    <h1 class="sr-only">Пароль</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Смена пароля"
            description="Пароль должен быть не короче 8 символов"
        />

        <Form
            v-bind="PasswordController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-6"
            novalidate
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="current_password">Текущий пароль</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    :aria-invalid="errors.current_password ? true : undefined"
                    aria-describedby="current_password-error"
                    autocomplete="current-password"
                />
                <InputError
                    id="current_password-error"
                    :message="errors.current_password"
                />
            </div>

            <div class="grid gap-2">
                <Label for="password">Новый пароль</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    :aria-invalid="errors.password ? true : undefined"
                    aria-describedby="password-error"
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                />
                <InputError id="password-error" :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Новый пароль ещё раз</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    :aria-invalid="
                        errors.password_confirmation ? true : undefined
                    "
                    aria-describedby="password_confirmation-error"
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                />
                <InputError
                    id="password_confirmation-error"
                    :message="errors.password_confirmation"
                />
            </div>

            <Button
                size="lg"
                class="w-full sm:w-auto"
                :disabled="processing"
                data-test="update-password-button"
            >
                Сохранить
            </Button>
        </Form>
    </div>
</template>
