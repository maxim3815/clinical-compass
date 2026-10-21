<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import DoseController from '@/actions/App/Http/Controllers/Patient/DoseController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useDoseWords } from '@/composables/useDoseWords';
import type { Dose, SelectOption } from '@/types';

defineProps<{
    reasons: SelectOption[];
}>();

// Приём, который отмечает пациент. Пока его нет, диалог закрыт.
const dose = defineModel<Dose | null>('dose', { required: true });

// С какой отметки открыть форму: «Принял в другое время» или «Не принял».
const status = defineModel<'taken' | 'skipped'>('status', { required: true });

const words = useDoseWords();

const form = useForm({
    status: 'taken' as 'taken' | 'skipped',
    time: '',
    reason: '',
    comment: '',
});

const cancelForm = useForm({});

// Новая отметка открывается сразу нужной формой. Выбор «Принял» или «Не принял» есть
// только при правке: там же отметку можно отменить.
const isMarked = computed(
    () => dose.value?.status === 'taken' || dose.value?.status === 'skipped',
);

const title = computed(() => {
    if (isMarked.value) {
        return 'Изменить отметку';
    }

    return form.status === 'taken'
        ? 'Во сколько вы приняли?'
        : 'Почему не получилось принять?';
});

const statuses = computed(() => [
    { value: 'taken', label: words.value.took },
    { value: 'skipped', label: words.value.didNotTake },
]);

// Для «Другое» комментарий обязателен.
const commentRequired = computed(() => form.reason === 'other');

// Приём, к которому вернуть фокус: кнопка, открывшая окно, после отметки пропадает.
let markedId: number | null = null;

watch(dose, (value) => {
    form.clearErrors();

    if (value !== null) {
        markedId = value.id;
        form.status = status.value;
        form.time = value.taken_time;
        form.reason = value.skip_reason ?? '';
        form.comment = value.skip_comment ?? '';
    }
});

// После правки поля прежняя ошибка уже не относится к введённому.
watch(
    () => [form.status, form.time, form.reason, form.comment],
    () => form.clearErrors(),
);

const open = computed({
    get: () => dose.value !== null,
    set: (value: boolean) => {
        if (!value) {
            dose.value = null;
        }
    },
});

function submit(): void {
    if (dose.value === null) {
        return;
    }

    form.submit(DoseController.update(dose.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            dose.value = null;
        },
    });
}

function unmark(): void {
    if (dose.value === null) {
        return;
    }

    cancelForm.submit(DoseController.cancel(dose.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            dose.value = null;
        },
    });
}

// Если отметку отменили и приём снова «пора принять», статуса нет: фокус на «Принял».
function focusDose(event: Event): void {
    const dose = `[data-dose-id="${markedId}"]`;
    const target = document.querySelector<HTMLElement>(
        `${dose} [data-test="dose-status"], ${dose} [data-test="take-dose"]`,
    );

    if (target !== null) {
        event.preventDefault();
        target.focus();
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <!-- Диалог открывается вне каркаса пациента: ошибки полей тоже 16 px. -->
        <DialogContent
            class="[&_[data-slot=input-error]]:text-base"
            data-test="dose-mark-dialog"
            @close-auto-focus="focusDose"
        >
            <form
                class="flex flex-col gap-5"
                novalidate
                @submit.prevent="submit"
            >
                <DialogHeader>
                    <DialogTitle class="text-xl">{{ title }}</DialogTitle>
                    <DialogDescription v-if="dose" class="text-base">
                        {{ dose.drug }}, {{ dose.dose }}. Приём {{ dose.day }} в
                        {{ dose.time }}.
                    </DialogDescription>
                </DialogHeader>

                <fieldset v-if="isMarked" class="grid grid-cols-2 gap-2">
                    <legend class="sr-only">Отметка</legend>
                    <label
                        v-for="option in statuses"
                        :key="option.value"
                        class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-input bg-card px-3 py-2 text-base transition-colors has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid"
                    >
                        <input
                            v-model="form.status"
                            type="radio"
                            name="status"
                            :value="option.value"
                            class="size-5 shrink-0 outline-none"
                        />
                        {{ option.label }}
                    </label>
                </fieldset>

                <div v-if="form.status === 'taken'" class="grid gap-2">
                    <Label for="dose_time" class="text-base">
                        Время приёма
                    </Label>
                    <Input
                        id="dose_time"
                        v-model="form.time"
                        type="time"
                        name="time"
                        class="h-11 w-36 text-lg"
                        :aria-invalid="form.errors.time ? true : undefined"
                        aria-describedby="dose_time-error"
                    />
                    <InputError
                        id="dose_time-error"
                        :message="form.errors.time"
                    />
                </div>

                <template v-else>
                    <fieldset
                        class="flex flex-col gap-2"
                        aria-describedby="skip_reason-error"
                    >
                        <legend class="mb-2 text-base font-medium">
                            Причина
                        </legend>
                        <label
                            v-for="reason in reasons"
                            :key="reason.value"
                            class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-input bg-card px-3 py-2 text-base transition-colors has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid"
                        >
                            <input
                                v-model="form.reason"
                                type="radio"
                                name="reason"
                                :value="reason.value"
                                class="size-5 shrink-0 outline-none"
                                :aria-invalid="
                                    form.errors.reason ? true : undefined
                                "
                            />
                            {{ reason.label }}
                        </label>
                        <InputError
                            id="skip_reason-error"
                            :message="form.errors.reason"
                        />
                    </fieldset>

                    <div class="grid gap-2">
                        <Label for="skip_comment" class="text-base">
                            Комментарий{{
                                commentRequired
                                    ? ' (обязательно)'
                                    : ' (необязательно)'
                            }}
                        </Label>
                        <Textarea
                            id="skip_comment"
                            v-model="form.comment"
                            name="comment"
                            rows="3"
                            :aria-required="commentRequired"
                            :aria-invalid="
                                form.errors.comment ? true : undefined
                            "
                            aria-describedby="skip_comment-error"
                        />
                        <InputError
                            id="skip_comment-error"
                            :message="form.errors.comment"
                        />
                    </div>
                </template>

                <!-- На телефоне кнопки идут столбцом снизу вверх: «Сохранить» оказывается сверху. -->
                <DialogFooter>
                    <Button
                        v-if="isMarked"
                        type="button"
                        variant="ghost"
                        size="lg"
                        class="text-primary sm:mr-auto"
                        :disabled="cancelForm.processing"
                        data-test="cancel-dose-mark"
                        @click="unmark"
                    >
                        Отменить отметку
                    </Button>
                    <DialogClose as-child>
                        <Button type="button" variant="outline" size="lg">
                            Закрыть
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        size="lg"
                        :disabled="form.processing"
                        :aria-busy="form.processing"
                        data-test="save-dose-mark"
                    >
                        <Spinner v-if="form.processing" class="size-5" />
                        Сохранить
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
