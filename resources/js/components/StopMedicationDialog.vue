<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { MessageCircle } from '@lucide/vue';
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
import { show as chat } from '@/routes/patient/chat';
import type { Prescription } from '@/types';

// Препарат, приём которого пациент собрался прекратить. Пока его нет, диалог закрыт.
const prescription = defineModel<Prescription | null>('prescription', {
    required: true,
});

const form = useForm({});
const page = usePage();

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
                    вы больше не принимаете препарат, его отменил врач или вы
                    добавили его по ошибке. Препарат пропадёт из списка и с
                    экрана «Сегодня», прошлые отметки останутся.
                    <!-- Препарат с подсказкой «Расскажите врачу»: бросать приём самому не стоит. -->
                    <!-- У пациента терапевта чата нет: врачу говорят на приёме. -->
                    <template
                        v-if="
                            prescription.tell_doctor && page.props.withTherapist
                        "
                        >Если хотите прекратить приём, сначала скажите об этом
                        врачу на приёме.</template
                    ><template v-else-if="prescription.tell_doctor"
                        >Если хотите прекратить приём, сначала напишите
                        врачу.</template
                    >
                </DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <!-- Препарат с подсказкой: «сначала напишите врачу» — сразу отсюда. Без чата при терапевте. -->
                <Button
                    v-if="
                        prescription?.tell_doctor && !page.props.withTherapist
                    "
                    variant="outline"
                    size="lg"
                    as-child
                >
                    <Link :href="chat()" data-test="stop-medication-chat">
                        <MessageCircle />
                        Написать врачу
                    </Link>
                </Button>
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
