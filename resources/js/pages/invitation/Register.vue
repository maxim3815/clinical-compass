<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InvitationRegistrationController from '@/actions/App/Http/Controllers/InvitationRegistrationController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { consent } from '@/routes';

const props = defineProps<{
    token: string;
    patientName: string | null;
}>();

defineOptions({
    layout: {
        title: 'Регистрация пациента',
        description:
            'Врач пригласил вас в Клинический компас. Заполните форму, чтобы создать учётную запись.',
    },
});
</script>

<template>
    <Head title="Регистрация" />

    <Form
        v-bind="InvitationRegistrationController.store.form(props.token)"
        :reset-on-error="['password', 'password_confirmation']"
        class="flex flex-col gap-6"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-2">
            <Label for="name">ФИО</Label>
            <Input
                id="name"
                name="name"
                :default-value="patientName ?? ''"
                required
                autocomplete="name"
                placeholder="Фамилия Имя Отчество"
            />
            <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="login">Логин</Label>
            <Input
                id="login"
                name="login"
                required
                autocomplete="username"
                autocapitalize="none"
                spellcheck="false"
            />
            <p class="text-xs text-muted-foreground">
                От 3 до 32 символов: латинские буквы, цифры, точка, дефис и
                подчёркивание.
            </p>
            <InputError :message="errors.login" />
        </div>

        <div class="grid gap-2">
            <Label for="password">Пароль</Label>
            <PasswordInput
                id="password"
                name="password"
                required
                autocomplete="new-password"
            />
            <p class="text-xs text-muted-foreground">Не короче 8 символов.</p>
            <InputError :message="errors.password" />
        </div>

        <div class="grid gap-2">
            <Label for="password_confirmation">Пароль ещё раз</Label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                required
                autocomplete="new-password"
            />
            <InputError :message="errors.password_confirmation" />
        </div>

        <div class="grid gap-2">
            <Label for="birth_date">Дата рождения (необязательно)</Label>
            <Input
                id="birth_date"
                name="birth_date"
                type="date"
                autocomplete="bday"
            />
            <InputError :message="errors.birth_date" />
        </div>

        <div class="grid gap-2">
            <Label for="consent" class="flex items-start gap-3 leading-snug">
                <Checkbox id="consent" name="consent" />
                <span>
                    Даю
                    <a
                        :href="consent.url()"
                        target="_blank"
                        rel="noopener"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 hover:decoration-current!"
                    >
                        согласие на обработку персональных данных
                    </a>
                </span>
            </Label>
            <InputError :message="errors.consent" />
        </div>

        <Button
            type="submit"
            class="w-full"
            :disabled="processing"
            data-test="register-button"
        >
            <Spinner v-if="processing" />
            Зарегистрироваться
        </Button>
    </Form>
</template>
