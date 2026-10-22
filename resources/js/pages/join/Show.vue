<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { PinInputInput, PinInputRoot } from 'reka-ui';
import { computed, nextTick, ref, watch } from 'vue';
import JoinController from '@/actions/App/Http/Controllers/JoinController';
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';
import { doctorLine } from '@/lib/doctor';
import { login } from '@/routes';
import type { InvitingDoctor } from '@/types';

type Props = {
    joinCode: string;
    doctor: InvitingDoctor;
    // Ввод закрыт после неверных попыток: текст с оставшимся временем.
    locked: string | null;
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: Props) => ({
        title: 'Подключение к врачу',
        description: `Врач: ${doctorLine(props.doctor)}.`,
    }),
});

const PIN_LENGTH = 4;

const digits = ref<(number | undefined)[]>([]);
const form = useForm({ pin: '' });
const error = computed(() => form.errors.pin ?? props.locked ?? undefined);
const root = ref<HTMLElement | null>(null);

// Новая цифра снимает прошлую ошибку: следующая ошибка заново появится и будет озвучена.
watch(digits, (value) => {
    if (value.length > 0 && form.errors.pin) {
        form.clearErrors('pin');
    }
});

// Цифры набираются по порядку: фокус в клетке правее пустой переходит в первую пустую.
// Клавиатура при этом не закрывается: её уже открыло нажатие на клетку.
function focusFirstEmpty(event: FocusEvent): void {
    const cells = [
        ...(root.value?.querySelectorAll<HTMLInputElement>('[data-pin-cell]') ??
            []),
    ];
    const empty = cells.findIndex((cell) => cell.value === '');
    const current = cells.indexOf(event.target as HTMLInputElement);

    if (empty !== -1 && current > empty) {
        cells[empty].focus();
    }
}

// Четвёртая цифра — сразу проверка, без кнопки. Верный PIN ведёт в форму регистрации.
// Ячейки на время проверки не отключаются: иначе на телефоне закрывается клавиатура.
function submit(value: (number | undefined)[]): void {
    if (
        form.processing ||
        value.filter((digit) => digit !== undefined).length < PIN_LENGTH
    ) {
        return;
    }

    form.pin = value.join('');
    form.post(JoinController.store.url(props.joinCode), {
        preserveScroll: true,
        // Код не подошёл: ячейки пустые, можно сразу набирать заново.
        onError: async () => {
            digits.value = [];
            await nextTick();
            root.value?.querySelector('input')?.focus();
        },
    });
}
</script>

<template>
    <Head title="Подключение к врачу" />

    <form class="flex flex-col gap-4" @submit.prevent="submit(digits)">
        <p id="pin-label" class="text-base font-medium">
            Нажмите на клетку и наберите 4 цифры, которые назвал врач
        </p>
        <!--
            reka-ui в режиме otp отменяет нажатие на клетку правее первой пустой и ставит фокус
            скриптом, а на Android клавиатура от такого фокуса не открывается. Поэтому нажатие
            до клеток не доходит: клетка получает фокус сама и клавиатура открывается, а уже
            потом фокус переходит в первую пустую клетку (focusFirstEmpty).
        -->
        <div ref="root" @mousedown.capture.stop @focusin="focusFirstEmpty">
            <!--
                otp — клетки заполняются по порядку: нажатие на любую ведёт в первую пустую.
                autocomplete="off" на клетках перекрывает one-time-code из otp, иначе телефон
                подсказывал бы над клавиатурой чужие коды из SMS.
            -->
            <PinInputRoot
                v-model="digits"
                type="number"
                otp
                :disabled="locked !== null"
                role="group"
                class="flex justify-center gap-2 min-[400px]:gap-3"
                aria-labelledby="pin-label"
                data-test="join-pin"
                @complete="submit"
            >
                <PinInputInput
                    v-for="index in PIN_LENGTH"
                    :key="index"
                    v-focus="index === 1"
                    :index="index - 1"
                    :aria-label="`Цифра ${index} из ${PIN_LENGTH}`"
                    data-pin-cell
                    autocomplete="off"
                    :aria-invalid="error ? true : undefined"
                    aria-describedby="pin-error"
                    class="size-16 min-w-0 rounded-xl border border-input bg-card text-center text-3xl font-semibold tabular-nums shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid disabled:bg-muted disabled:opacity-70 aria-invalid:border-destructive"
                />
            </PinInputRoot>
        </div>
        <!--
            Ошибка и проверка сразу под клетками, чтобы их не закрыла клавиатура. Одновременно
            они не показываются: новая цифра снимает ошибку. Регион статуса живёт постоянно:
            вставленный сразу с текстом скринридеры часто не объявляют.
        -->
        <div class="-mt-1 flex flex-col">
            <InputError id="pin-error" class="text-center" :message="error" />
            <p
                class="flex items-center justify-center gap-2 text-base text-muted-foreground"
                role="status"
            >
                <template v-if="form.processing">
                    <Spinner aria-hidden="true" />
                    Проверяем код…
                </template>
            </p>
        </div>
    </form>

    <p class="text-base text-muted-foreground">
        Уже зарегистрированы?
        <Link
            :href="login()"
            class="text-primary underline underline-offset-4 pointer-coarse:py-2.5"
            >Войти</Link
        >
    </p>
</template>
