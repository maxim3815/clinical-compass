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
                <DialogTitle class="text-xl"
                    >Убрать препарат из списка?</DialogTitle
                >
                <DialogDescription v-if="prescription" class="text-base">
                    {{ prescription.drug.name }},
                    {{ prescription.kind_label.toLowerCase() }}. Уберите, если
                    вы больше не принимаете препарат или его отменил врач.
                    Препарат пропадёт из списка и с экрана «Сегодня», прошлые
                    отметки останутся.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <DialogClose as-child>
                    <Button type="button" variant="outline" size="lg">
                        Не убирать
                    </Button>
                </DialogClose>
                <!-- Опасности здесь нет: красный у пациента — только для опасного. -->
                <Button
                    type="button"
                    size="lg"
                    :disabled="form.processing"
                    data-test="confirm-stop-medication"
                    @click="submit"
                >
                    Убрать
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
