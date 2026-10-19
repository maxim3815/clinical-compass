<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import OwnMedicationController from '@/actions/App/Http/Controllers/Patient/OwnMedicationController';
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
import type { Prescription } from '@/types';

// Препарат, приём которого пациент собрался прекратить. Пока его нет, диалог закрыт.
const prescription = defineModel<Prescription | null>('prescription', {
    required: true,
});

const form = useForm({});

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

    form.submit(OwnMedicationController.stop(prescription.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            prescription.value = null;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="stop-medication-dialog">
            <DialogHeader>
                <DialogTitle>Прекратить приём?</DialogTitle>
                <DialogDescription v-if="prescription">
                    {{ prescription.drug.name }},
                    {{ prescription.kind_label.toLowerCase() }}. Препарат
                    пропадёт из списка, будущих приёмов в «Сегодня» не будет.
                    Отметки прошлых приёмов сохранятся.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <DialogClose as-child>
                    <Button type="button" variant="outline">
                        Не прекращать
                    </Button>
                </DialogClose>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="form.processing"
                    data-test="confirm-stop-medication"
                    @click="submit"
                >
                    Прекратить приём
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
