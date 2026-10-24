<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CircleCheck } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AsNeededDoseController from '@/actions/App/Http/Controllers/Patient/AsNeededDoseController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useDoseWords } from '@/composables/useDoseWords';
import { plural } from '@/lib/plural';
import type { AsNeeded } from '@/types';

const props = defineProps<{
    prescription: AsNeeded;
}>();

const words = useDoseWords();

const form = useForm({
    day: 'today' as 'today' | 'yesterday',
    time: '',
    confirmed: false,
});

// Форма «Принял в другое время», предупреждение о лимите за сутки или вопрос перед удалением.
const dialog = ref<'time' | 'limit' | 'delete' | null>(null);

const open = computed({
    get: () => dialog.value !== null,
    set: (value: boolean) => {
        if (!value) {
            dialog.value = null;
            form.clearErrors();
        }
    },
});

// После правки поля прежняя ошибка уже не относится к введённому.
watch(
    () => [form.day, form.time],
    () => form.clearErrors('day', 'time'),
);

// «Сегодня принято 1 раз, можно не больше 3 раз в сутки»: предел, а не цель, как в «1 из 3».
const counter = computed(() => {
    const taken = props.prescription.taken_today;
    const limit = props.prescription.max_daily_doses;
    const text =
        taken === 0
            ? 'Сегодня ещё не принимали'
            : `Сегодня принято ${taken} ${plural(taken, ['раз', 'раза', 'раз'])}`;

    return limit === null
        ? text
        : `${text}, можно не больше ${limit} ${plural(limit, ['раза', 'раз', 'раз'])} в сутки`;
});

const limitReached = computed(
    () =>
        props.prescription.max_daily_doses !== null &&
        props.prescription.taken_today >= props.prescription.max_daily_doses,
);

function submit(confirmed = false): void {
    form.confirmed = confirmed;
    form.submit(AsNeededDoseController.store(props.prescription.id), {
        preserveScroll: true,
        onSuccess: () => {
            dialog.value = null;
            form.reset();
        },
        // Лимит за сутки исчерпан: пациент подтверждает отметку. Прежнее «Приём отмечен.»
        // рядом с вопросом сбивало бы с толку.
        onError: (errors) => {
            if (errors.confirmed) {
                toast.dismiss();
                dialog.value = 'limit';
            }
        },
    });
}

function takeNow(): void {
    form.reset();
    form.clearErrors();
    submit();
}

// Время заполнено текущим: обычно препарат приняли только что или недавно.
function openTimeForm(): void {
    const now = new Date();

    form.reset();
    form.clearErrors();
    form.time = [now.getHours(), now.getMinutes()]
        .map((part) => String(part).padStart(2, '0'))
        .join(':');
    dialog.value = 'time';
}

const days = [
    { value: 'today', label: 'Сегодня' },
    { value: 'yesterday', label: 'Вчера' },
] as const;

// Одинаковые кнопки у разных препаратов скринридер различает по названию.
const nameId = computed(() => `as-needed-${props.prescription.id}-name`);

const cancelForm = useForm({});

// Отметка убирается только после вопроса: случайное касание не стирает приём.
const deleting = ref<AsNeeded['doses'][number] | null>(null);

function askDelete(dose: AsNeeded['doses'][number]): void {
    deleting.value = dose;
    dialog.value = 'delete';
}

function cancel(): void {
    if (deleting.value === null) {
        return;
    }

    cancelForm.submit(AsNeededDoseController.destroy(deleting.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            dialog.value = null;
        },
    });
}
</script>

<template>
    <article
        class="flex flex-col gap-4 rounded-2xl border bg-card p-4 break-words shadow-xs"
        data-test="as-needed"
    >
        <div>
            <h3 :id="nameId" class="text-lg font-semibold">
                {{ prescription.drug }}
            </h3>
            <p>{{ prescription.dose }}</p>
            <p v-if="prescription.instruction" class="text-muted-foreground">
                {{ prescription.instruction }}
            </p>
        </div>

        <p
            class="font-medium"
            :class="limitReached ? 'text-warning' : ''"
            data-test="as-needed-counter"
        >
            {{ counter }}
        </p>

        <div class="flex flex-col gap-1">
            <Button
                size="lg"
                variant="secondary"
                class="w-full"
                :disabled="form.processing"
                :aria-busy="form.processing"
                :aria-describedby="nameId"
                data-test="take-as-needed"
                @click="takeNow"
            >
                <Spinner v-if="form.processing && !open" class="size-5" />
                <CircleCheck v-else />
                {{ words.took }}
            </Button>
            <Button
                size="lg"
                variant="ghost"
                class="self-center px-3 text-primary"
                :aria-describedby="nameId"
                data-test="take-as-needed-at"
                @click="openTimeForm"
            >
                {{ words.tookAtAnotherTime }}
            </Button>
        </div>

        <ul
            v-if="prescription.doses.length"
            class="flex flex-col divide-y border-t"
            aria-label="Отмеченные приёмы"
        >
            <li
                v-for="dose in prescription.doses"
                :key="dose.id"
                class="flex items-center justify-between gap-2 pt-1 first:pt-2"
                data-test="as-needed-dose"
            >
                <span>{{ dose.day }} в {{ dose.time }}</span>
                <Button
                    size="lg"
                    variant="ghost"
                    :disabled="cancelForm.processing"
                    class="px-3 text-primary"
                    :aria-label="`Убрать отметку: ${dose.day.toLowerCase()} в ${dose.time}`"
                    data-test="delete-as-needed"
                    @click="askDelete(dose)"
                >
                    Убрать отметку
                </Button>
            </li>
        </ul>

        <Dialog v-model:open="open">
            <DialogContent
                v-if="dialog === 'delete' && deleting"
                data-test="as-needed-delete-dialog"
            >
                <DialogHeader>
                    <DialogTitle class="text-xl">Убрать отметку?</DialogTitle>
                    <DialogDescription class="text-base">
                        {{ prescription.drug }}:
                        {{ deleting.day.toLowerCase() }} в {{ deleting.time }}.
                        Уберите, если отметили приём по ошибке.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        size="lg"
                        @click="open = false"
                    >
                        Не убирать
                    </Button>
                    <Button
                        type="button"
                        size="lg"
                        :disabled="cancelForm.processing"
                        :aria-busy="cancelForm.processing"
                        data-test="confirm-delete-as-needed"
                        @click="cancel"
                    >
                        <Spinner v-if="cancelForm.processing" class="size-5" />
                        Убрать
                    </Button>
                </DialogFooter>
            </DialogContent>

            <DialogContent
                v-else-if="dialog === 'limit'"
                data-test="as-needed-limit-dialog"
            >
                <DialogHeader>
                    <DialogTitle class="text-xl">
                        Отметить ещё один приём?
                    </DialogTitle>
                    <DialogDescription class="text-base">
                        {{ form.errors.confirmed }} Если вы приняли его ещё раз,
                        отметьте и этот приём: так врачу будет проще подобрать
                        лечение.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        size="lg"
                        @click="open = false"
                    >
                        Не отмечать
                    </Button>
                    <Button
                        type="button"
                        size="lg"
                        :disabled="form.processing"
                        :aria-busy="form.processing"
                        data-test="confirm-as-needed"
                        @click="submit(true)"
                    >
                        <Spinner v-if="form.processing" class="size-5" />
                        Отметить
                    </Button>
                </DialogFooter>
            </DialogContent>

            <DialogContent
                v-else
                class="[&_[data-slot=input-error]]:text-base"
                data-test="as-needed-time-dialog"
            >
                <form class="flex flex-col gap-5" @submit.prevent="submit()">
                    <DialogHeader>
                        <DialogTitle class="text-xl">
                            Когда вы приняли препарат?
                        </DialogTitle>
                        <DialogDescription class="text-base">
                            {{ prescription.drug }}, {{ prescription.dose }}.
                        </DialogDescription>
                    </DialogHeader>

                    <fieldset
                        class="flex flex-col gap-2"
                        aria-describedby="as_needed_day-error"
                    >
                        <legend class="mb-2 text-base font-medium">День</legend>
                        <div class="grid grid-cols-2 gap-2">
                            <label
                                v-for="day in days"
                                :key="day.value"
                                class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-input bg-card px-3 text-base transition-colors has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid"
                            >
                                <input
                                    v-model="form.day"
                                    type="radio"
                                    name="day"
                                    :value="day.value"
                                    class="size-5 shrink-0 outline-none"
                                />
                                {{ day.label }}
                            </label>
                        </div>
                        <InputError
                            id="as_needed_day-error"
                            :message="form.errors.day"
                        />
                    </fieldset>

                    <div class="grid gap-2">
                        <Label for="as_needed_time" class="text-base"
                            >Время</Label
                        >
                        <Input
                            id="as_needed_time"
                            v-model="form.time"
                            type="time"
                            name="time"
                            class="h-11 w-36 text-lg"
                            :aria-invalid="form.errors.time ? true : undefined"
                            aria-describedby="as_needed_time-error"
                        />
                        <InputError
                            id="as_needed_time-error"
                            :message="form.errors.time"
                        />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            size="lg"
                            @click="open = false"
                        >
                            Закрыть
                        </Button>
                        <Button
                            type="submit"
                            size="lg"
                            :disabled="form.processing"
                            :aria-busy="form.processing"
                            data-test="save-as-needed"
                        >
                            <Spinner v-if="form.processing" class="size-5" />
                            Отметить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </article>
</template>
