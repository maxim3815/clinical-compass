<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { home } from '@/routes';
import { store } from '@/routes/password/confirm';

defineOptions({
    layout: {
        title: 'Подтвердите пароль',
        description: 'Чтобы продолжить, введите ваш пароль.',
    },
});
</script>

<template>
    <Head title="Подтверждение пароля" />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
        novalidate
    >
        <div class="space-y-6">
            <div class="grid gap-2">
                <Label for="password">Пароль</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    autofocus
                    :aria-invalid="errors.password ? true : undefined"
                    aria-describedby="password-error"
                />

                <InputError id="password-error" :message="errors.password" />
            </div>

            <div class="flex flex-col gap-3">
                <Button
                    size="lg"
                    class="w-full"
                    :disabled="processing"
                    data-test="confirm-password-button"
                >
                    <Spinner v-if="processing" />
                    Подтвердить
                </Button>
                <Button variant="outline" size="lg" class="w-full" as-child>
                    <Link :href="home()">Отмена</Link>
                </Button>
            </div>
        </div>
    </Form>
</template>
