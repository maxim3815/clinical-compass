<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { CircleAlert } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';

defineOptions({
    layout: {
        title: 'Вход',
        description: 'Введите логин и пароль',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Вход" />

    <p
        v-if="status"
        class="rounded-lg border border-success-border bg-success-subtle px-4 py-3 text-base font-medium text-success"
    >
        {{ status }}
    </p>

    <!--
        Пустые поля ловит сам браузер (подсказка на языке телефона), поэтому с сервера
        приходит только «Неверный логин или пароль» или лимит попыток: это про оба поля сразу.
    -->
    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <!-- «Неверный логин или пароль» относится к обоим полям, поэтому ошибка входа — над ними. -->
        <Alert
            v-if="errors.login"
            variant="destructive"
            data-test="login-error"
        >
            <CircleAlert />
            <AlertDescription id="login-error" class="text-base">
                {{ errors.login }}
            </AlertDescription>
        </Alert>

        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label for="login">Логин</Label>
                <Input
                    id="login"
                    type="text"
                    name="login"
                    required
                    v-focus
                    autocomplete="username"
                    autocapitalize="none"
                    spellcheck="false"
                    :aria-invalid="errors.login ? true : undefined"
                    :aria-describedby="errors.login ? 'login-error' : undefined"
                />
            </div>

            <div class="grid gap-2">
                <Label for="password">Пароль</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    :aria-invalid="
                        errors.login || errors.password ? true : undefined
                    "
                    :aria-describedby="
                        errors.login
                            ? 'login-error'
                            : errors.password
                              ? 'password-error'
                              : undefined
                    "
                />
                <InputError id="password-error" :message="errors.password" />
            </div>

            <Label
                for="remember"
                class="flex min-h-11 items-center gap-3 text-base font-normal"
            >
                <Checkbox id="remember" name="remember" />
                <span>Запомнить меня</span>
            </Label>

            <Button
                type="submit"
                size="lg"
                class="w-full"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Войти
            </Button>
        </div>

        <div class="space-y-2 border-t pt-5 text-base text-muted-foreground">
            <p>
                Забыли пароль? Попросите у врача ссылку, по которой можно задать
                новый.
            </p>
            <p>Нет учётной записи? Ссылку для регистрации присылает врач.</p>
        </div>
    </Form>
</template>
