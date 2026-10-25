<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref, useTemplateRef } from 'vue';
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
import { doctorLine } from '@/lib/doctor';
import type { InvitingDoctor } from '@/types';

type Props = {
    token: string;
    patientName: string | null;
    doctor: InvitingDoctor;
};

const props = defineProps<Props>();

const consentGiven = ref(false);
const consentOpen = ref(false);
const consentTitle =
    useTemplateRef<InstanceType<typeof DialogTitle>>('consentTitle');
// Окно закрыто кнопкой «Даю согласие»: фокус на галочке, скринридер скажет, что она отмечена.
let agreed = false;

// Прочитал текст и согласился в окне — галочка ставится сама.
function agree(): void {
    agreed = true;
    consentGiven.value = true;
    consentOpen.value = false;
}

// Фокус на заголовке: иначе он попадает на «Закрыть» в конце длинного текста.
function focusConsentTitle(event: Event): void {
    event.preventDefault();
    consentTitle.value?.$el.focus();
}

function focusConsent(event: Event): void {
    if (agreed) {
        event.preventDefault();
        document.getElementById('consent')?.focus();
        agreed = false;
    }
}

defineOptions({
    layout: (props: Props) => ({
        title: 'Регистрация пациента',
        description: `Вас приглашает врач: ${doctorLine(props.doctor)}. Заполните форму, чтобы зарегистрироваться.`,
    }),
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
                От 3 до 32 знаков: латинские буквы, цифры, точка, дефис и знак
                «_».
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
                    :aria-invalid="
                        errors.consent && !consentGiven ? true : undefined
                    "
                    aria-describedby="consent-error"
                />
                <span
                    >Даю согласие на обработку персональных данных, в том числе
                    сведений о здоровье</span
                >
            </Label>
            <!-- Галочку поставили: ошибка уже неверна. -->
            <InputError
                id="consent-error"
                :message="consentGiven ? undefined : errors.consent"
            />
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
                <!--
                    Текст длинный: заголовок с крестиком и кнопки закреплены, прокручивается только
                    текст. Так из середины текста видно, как закрыть окно и как согласиться. На низком
                    экране (телефон боком, крупный шрифт) места под текст не осталось бы: там окно
                    прокручивается целиком.
                -->
                <DialogContent
                    class="flex flex-col gap-0 overflow-hidden p-0 [@media(max-height:32rem)]:overflow-y-auto"
                    @open-auto-focus="focusConsentTitle"
                    @close-auto-focus="focusConsent"
                >
                    <DialogHeader class="border-b px-6 pt-5 pb-3">
                        <DialogTitle
                            ref="consentTitle"
                            tabindex="-1"
                            class="text-xl outline-none"
                        >
                            Согласие на обработку данных
                        </DialogTitle>
                        <DialogDescription class="sr-only">
                            Текст согласия, которое вы даёте при регистрации
                        </DialogDescription>
                    </DialogHeader>
                    <div
                        class="min-h-0 overflow-y-auto px-6 py-4 outline-none focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid [@media(max-height:32rem)]:shrink-0 [@media(max-height:32rem)]:overflow-visible"
                        tabindex="0"
                        role="region"
                        aria-label="Текст согласия"
                        data-test="consent-text"
                    >
                        <ConsentText heading="h3" />
                    </div>
                    <!-- Кнопки в ряд: закреплённая часть окна ниже, тексту больше места. -->
                    <DialogFooter class="flex-row border-t px-6 pt-3 pb-5">
                        <DialogClose as-child>
                            <Button
                                type="button"
                                variant="outline"
                                size="lg"
                                class="flex-1"
                            >
                                Закрыть
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            size="lg"
                            class="flex-1"
                            data-test="consent-agree"
                            @click="agree"
                        >
                            Даю согласие
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
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
