<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import PrescriptionController from '@/actions/App/Http/Controllers/Doctor/PrescriptionController';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { Prescription, SelectOption } from '@/types';

const props = defineProps<{
    patientId: number;
    reasons: SelectOption[];
}>();

// Назначение, которое врач собрался отменить. Пока его нет, диалог закрыт.
const prescription = defineModel<Prescription | null>('prescription', {
    required: true,
});

// Причину врач выбирает явно, «Не указывать» — тоже выбор: заранее отмеченный вариант
// в спешке оставили бы, и в истории пропала бы причина отмены.
const form = useForm<{ reason: string | null; comment: string }>({
    reason: null,
    comment: '',
});

watch(prescription, () => {
    form.reset();
    form.clearErrors();
});

watch(
    () => form.reason,
    () => form.clearErrors('reason'),
);

const open = computed({
    get: () => prescription.value !== null,
    set: (value: boolean) => {
        if (!value) {
            prescription.value = null;
        }
    },
});

function submit(): void {
    if (prescription.value === null) {
        return;
    }

    if (form.reason === null) {
        form.setError('reason', 'Выберите причину или «Не указывать».');
        document.querySelector<HTMLElement>('input[name="reason"]')?.focus();

        return;
    }

    form.transform((data) => ({
        ...data,
        reason: data.reason === 'none' ? '' : data.reason,
    })).submit(
        PrescriptionController.cancel({
            patient: props.patientId,
            prescription: prescription.value.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                prescription.value = null;
            },
            onError: () =>
                document
                    .querySelector<HTMLElement>(
                        '[data-test="cancel-prescription-dialog"] [aria-invalid="true"]',
                    )
                    ?.focus(),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="cancel-prescription-dialog">
            <form
                class="flex flex-col gap-4"
                novalidate
                @submit.prevent="submit"
            >
                <DialogHeader>
                    <DialogTitle>Отменить назначение?</DialogTitle>
                    <DialogDescription v-if="prescription">
                        {{ prescription.drug.name }},
                        {{ prescription.strength }},
                        {{ prescription.kind_label.toLowerCase() }}. Назначение
                        перестанет действовать сейчас, напоминания пациенту
                        прекратятся. Отдельного уведомления об отмене он не
                        получит.
                    </DialogDescription>
                </DialogHeader>

                <fieldset
                    class="flex flex-col gap-2"
                    aria-describedby="cancel_reason-error"
                >
                    <legend class="mb-2 text-sm font-medium">Причина</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="reason in [
                                ...reasons,
                                { value: 'none', label: 'Не указывать' },
                            ]"
                            :key="reason.value"
                            class="flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-input bg-card px-3 py-2 text-sm transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid pointer-coarse:min-h-11"
                        >
                            <input
                                v-model="form.reason"
                                type="radio"
                                name="reason"
                                :value="reason.value"
                                class="size-4 shrink-0 outline-none"
                                :aria-invalid="
                                    form.errors.reason ? true : undefined
                                "
                            />
                            {{ reason.label }}
                        </label>
                    </div>
                    <InputError
                        id="cancel_reason-error"
                        :message="form.errors.reason"
                    />
                </fieldset>

                <div class="grid gap-2">
                    <Label for="cancel_comment">
                        Комментарий{{
                            form.reason === 'other'
                                ? ' (обязательно)'
                                : ' (необязательно)'
                        }}
                    </Label>
                    <Textarea
                        id="cancel_comment"
                        v-model="form.comment"
                        name="comment"
                        rows="3"
                        :aria-required="form.reason === 'other'"
                        :aria-invalid="form.errors.comment ? true : undefined"
                        aria-describedby="cancel_comment-error"
                    />
                    <InputError
                        id="cancel_comment-error"
                        :message="form.errors.comment"
                    />
                </div>

                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline">
                            Не отменять
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="form.processing"
                        :aria-busy="form.processing"
                        data-test="confirm-cancel-prescription"
                    >
                        <Spinner v-if="form.processing" />
                        Отменить назначение
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
