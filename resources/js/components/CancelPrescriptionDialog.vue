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
import { NativeSelect } from '@/components/ui/native-select';
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

const form = useForm({
    reason: '',
    comment: '',
});

watch(prescription, () => {
    form.reset();
    form.clearErrors();
});

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

    form.submit(
        PrescriptionController.cancel({
            patient: props.patientId,
            prescription: prescription.value.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                prescription.value = null;
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="cancel-prescription-dialog">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Отменить назначение?</DialogTitle>
                    <DialogDescription v-if="prescription">
                        {{ prescription.drug.name }},
                        {{ prescription.strength }},
                        {{ prescription.kind_label.toLowerCase() }}. Назначение
                        перестанет действовать сейчас и уйдёт в историю.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="cancel_reason">Причина</Label>
                    <NativeSelect
                        id="cancel_reason"
                        v-model="form.reason"
                        name="reason"
                    >
                        <option value="">Не указывать</option>
                        <option
                            v-for="reason in reasons"
                            :key="reason.value"
                            :value="reason.value"
                        >
                            {{ reason.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.reason" />
                </div>

                <div class="grid gap-2">
                    <Label for="cancel_comment">
                        Комментарий{{
                            form.reason === 'other' ? '' : ' (необязательно)'
                        }}
                    </Label>
                    <Textarea
                        id="cancel_comment"
                        v-model="form.comment"
                        name="comment"
                        rows="3"
                    />
                    <InputError :message="form.errors.comment" />
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
                        data-test="confirm-cancel-prescription"
                    >
                        Отменить назначение
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
