<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import PasswordResetByLinkController from '@/actions/App/Http/Controllers/PasswordResetByLinkController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    token: string;
    login: string;
}>();

defineOptions({
    layout: {
        title: 'Новый пароль',
        description:
            'Задайте новый пароль: кабинет откроется сразу, а на других устройствах придётся войти заново.',
    },
});
</script>

<template>
    <Head title="Новый пароль" />

    <Form
        v-bind="PasswordResetByLinkController.store.form(props.token)"
        :reset-on-error="['password', 'password_confirmation']"
        class="flex flex-col gap-6"
        v-slot="{ errors, processing }"
        novalidate
    >
        <div class="grid gap-2">
            <Label for="login">Ваш логин</Label>
            <Input
                id="login"
                :default-value="login"
                autocomplete="username"
                readonly
            />
        </div>

        <div class="grid gap-2">
            <Label for="password">Новый пароль</Label>
            <PasswordInput
                id="password"
                name="password"
                required
                autofocus
                autocomplete="new-password"
                :aria-invalid="errors.password ? true : undefined"
                aria-describedby="password-hint password-error"
            />
            <p id="password-hint" class="text-muted-foreground">
                Не короче 8 символов.
            </p>
            <InputError id="password-error" :message="errors.password" />
        </div>

        <div class="grid gap-2">
            <Label for="password_confirmation">Новый пароль ещё раз</Label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                required
                autocomplete="new-password"
                :aria-invalid="errors.password_confirmation ? true : undefined"
                aria-describedby="password-confirmation-error"
            />
            <InputError
                id="password-confirmation-error"
                :message="errors.password_confirmation"
            />
        </div>

        <Button
            type="submit"
            size="lg"
            class="w-full"
            :disabled="processing"
            data-test="reset-password-button"
        >
            <Spinner v-if="processing" />
            Сохранить пароль
        </Button>
    </Form>
</template>
