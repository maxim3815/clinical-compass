<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InvitationRegistrationController from '@/actions/App/Http/Controllers/InvitationRegistrationController';
import ConsentText from '@/components/ConsentText.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    token: string;
    patientName: string | null;
}>();

const consentGiven = ref(false);
const consentOpen = ref(false);

// Прочитал текст и согласился в окне — галочка ставится сама.
function agree(): void {
    consentGiven.value = true;
    consentOpen.value = false;
}

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

    <!--
        novalidate: ошибки приходят с сервера по-русски и стоят под своими полями.
        Пароли после ошибки не стираются: из-за пустого поля или галочки их не придётся набирать заново.
    -->
    <Form
        v-bind="InvitationRegistrationController.store.form(props.token)"
        class="flex flex-col gap-6"
        v-slot="{ errors, processing }"
        novalidate
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
                :aria-invalid="errors.name ? true : undefined"
                aria-describedby="name-error"
            />
            <InputError id="name-error" :message="errors.name" />
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
                :aria-invalid="errors.login ? true : undefined"
                aria-describedby="login-hint login-error"
            />
            <p id="login-hint" class="text-muted-foreground">
                От 3 до 32 символов: латинские буквы, цифры, точка, дефис и
                подчёркивание.
            </p>
            <InputError id="login-error" :message="errors.login" />
        </div>

        <div class="grid gap-2">
            <Label for="password">Пароль</Label>
            <PasswordInput
                id="password"
                name="password"
                required
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
            <Label for="password_confirmation">Пароль ещё раз</Label>
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

        <div class="grid gap-2">
            <Label for="birth_date">Дата рождения (необязательно)</Label>
            <Input
                id="birth_date"
                name="birth_date"
                type="date"
                autocomplete="bday"
                :aria-invalid="errors.birth_date ? true : undefined"
                aria-describedby="birth-date-error"
            />
            <InputError id="birth-date-error" :message="errors.birth_date" />
        </div>

        <div class="grid gap-2">
            <!-- Подпись только ставит галочку, а текст согласия открывается рядом в окне. -->
            <Label
                for="consent"
                class="flex min-h-11 items-start gap-3 text-base leading-snug font-normal"
            >
                <Checkbox
                    id="consent"
                    v-model="consentGiven"
                    name="consent"
                    class="mt-0.5"
                    :aria-invalid="errors.consent ? true : undefined"
                    aria-describedby="consent-error"
                />
                <span>Даю согласие на обработку персональных данных</span>
            </Label>
            <Dialog v-model:open="consentOpen">
                <DialogTrigger as-child>
                    <Button
                        type="button"
                        variant="link"
                        class="h-auto justify-self-start px-0 py-1 text-base"
                        data-test="consent-text-button"
                    >
                        Прочитать текст согласия
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Согласие на обработку персональных данных
                        </DialogTitle>
                        <DialogDescription class="sr-only">
                            Текст согласия, которое даёт пациент при регистрации
                        </DialogDescription>
                    </DialogHeader>
                    <ConsentText />
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button type="button" variant="outline" size="lg">
                                Закрыть
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            size="lg"
                            data-test="consent-agree"
                            @click="agree"
                        >
                            Согласен
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <InputError id="consent-error" :message="errors.consent" />
        </div>

        <Button
            type="submit"
            size="lg"
            class="w-full"
            :disabled="processing"
            data-test="register-button"
        >
            <Spinner v-if="processing" />
            Зарегистрироваться
        </Button>
    </Form>
</template>
