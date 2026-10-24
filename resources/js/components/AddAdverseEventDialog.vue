<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AdverseEventController from '@/actions/App/Http/Controllers/Doctor/AdverseEventController';
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
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { SelectOption, SideEffectOption } from '@/types';

/**
 * Запись нежелательного явления со слов пациента на приёме: симптом из словаря или свой текст,
 * насколько мешает, начало и комментарий. Алерта и очереди нет, пациент видит запись в своём списке.
 */
const props = defineProps<{
    patientId: number;
    symptoms: SideEffectOption[];
    severities: SelectOption[];
    limits: { name: number; comment: number; today: string; min_date: string };
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm<{
    symptom_id: number | 'other' | null;
    other: string;
    severity: string | null;
    started_on: string;
    comment: string;
}>({
    symptom_id: null,
    other: '',
    severity: null,
    // Начало врач указывает сам: подставленное «сегодня» легко сохранить по ошибке.
    started_on: '',
    comment: '',
});

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
    }
});

watch(
    () => [form.symptom_id, form.other],
    () => form.clearErrors('symptom_id', 'other'),
);

const isOther = computed(() => form.symptom_id === 'other');

function submit(): void {
    form.transform((data) => ({
        ...data,
        symptom_id: data.symptom_id === 'other' ? null : data.symptom_id,
        other: data.symptom_id === 'other' ? data.other : '',
    })).submit(AdverseEventController.store(props.patientId), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        onError: () =>
            document
                .querySelector<HTMLElement>(
                    '[data-test="add-adverse-event-dialog"] [aria-invalid="true"]',
                )
                ?.focus(),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="add-adverse-event-dialog">
            <form
                class="flex flex-col gap-4"
                novalidate
                @submit.prevent="submit"
            >
                <DialogHeader>
                    <DialogTitle>Запись со слов пациента</DialogTitle>
                    <DialogDescription>
                        Алерта не будет. Пациент увидит запись в своём списке
                        побочных эффектов и сможет отметить, что прошло.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="adverse_symptom">Симптом</Label>
                    <NativeSelect
                        id="adverse_symptom"
                        v-model="form.symptom_id"
                        name="symptom_id"
                        :aria-invalid="
                            form.errors.symptom_id ? true : undefined
                        "
                        aria-describedby="adverse_symptom-error"
                    >
                        <option :value="null" disabled>Выберите симптом</option>
                        <option
                            v-for="symptom in symptoms"
                            :key="symptom.id"
                            :value="symptom.id"
                        >
                            {{
                                symptom.term === symptom.name
                                    ? symptom.term
                                    : `${symptom.term} — ${symptom.name.toLowerCase()}`
                            }}
                        </option>
                        <option value="other">Другое</option>
                    </NativeSelect>
                    <InputError
                        id="adverse_symptom-error"
                        :message="form.errors.symptom_id"
                    />
                </div>

                <div v-if="isOther" class="grid gap-2">
                    <Label for="adverse_other">Что беспокоит</Label>
                    <Input
                        id="adverse_other"
                        v-model="form.other"
                        name="other"
                        :maxlength="limits.name"
                        :aria-invalid="form.errors.other ? true : undefined"
                        aria-describedby="adverse_other-error"
                    />
                    <InputError
                        id="adverse_other-error"
                        :message="form.errors.other"
                    />
                </div>

                <fieldset
                    class="flex flex-col gap-2"
                    aria-describedby="adverse_severity-error"
                >
                    <legend class="mb-2 text-sm font-medium">
                        Насколько мешает
                    </legend>
                    <label
                        v-for="severity in severities"
                        :key="severity.value"
                        :data-test="`adverse-severity-${severity.value}`"
                        class="flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-input bg-card px-3 py-2 text-sm transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid pointer-coarse:min-h-11"
                    >
                        <input
                            v-model="form.severity"
                            type="radio"
                            name="severity"
                            :value="severity.value"
                            class="size-4 shrink-0 outline-none"
                            :aria-invalid="
                                form.errors.severity ? true : undefined
                            "
                        />
                        {{ severity.label }}
                    </label>
                    <InputError
                        id="adverse_severity-error"
                        :message="form.errors.severity"
                    />
                </fieldset>

                <div class="grid gap-2">
                    <Label for="adverse_started_on">Начало</Label>
                    <Input
                        id="adverse_started_on"
                        v-model="form.started_on"
                        name="started_on"
                        type="date"
                        :min="limits.min_date"
                        :max="limits.today"
                        :aria-invalid="
                            form.errors.started_on ? true : undefined
                        "
                        aria-describedby="adverse_started_on-error"
                    />
                    <InputError
                        id="adverse_started_on-error"
                        :message="form.errors.started_on"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="adverse_comment">
                        Комментарий (необязательно)
                    </Label>
                    <Textarea
                        id="adverse_comment"
                        v-model="form.comment"
                        name="comment"
                        rows="3"
                        :maxlength="limits.comment"
                        :aria-invalid="form.errors.comment ? true : undefined"
                        aria-describedby="adverse_comment-error"
                    />
                    <InputError
                        id="adverse_comment-error"
                        :message="form.errors.comment"
                    />
                </div>

                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        :aria-busy="form.processing"
                        data-test="confirm-add-adverse-event"
                    >
                        <Spinner v-if="form.processing" />
                        Добавить запись
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
