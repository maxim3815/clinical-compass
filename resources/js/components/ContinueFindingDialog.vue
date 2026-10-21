<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import SafetyFindingController from '@/actions/App/Http/Controllers/Doctor/SafetyFindingController';
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
import { Textarea } from '@/components/ui/textarea';
import type { SafetyFinding } from '@/types';

const props = defineProps<{
    patientId: number;
    maxLength: number;
}>();

// Находка, по которой врач решает. Пока её нет, диалог закрыт.
const finding = defineModel<{ finding: SafetyFinding; drug: string } | null>(
    'finding',
    { required: true },
);

const form = useForm({ justification: '' });

// Одной строкой: текст и <template v-if> подряд на сервере сливаются в один узел.
const description = computed(() => {
    if (finding.value === null) {
        return '';
    }

    const { drug, finding: item } = finding.value;
    const related =
        item.related.length > 0
            ? ` Решение относится и к находке у препарата ${item.related.join(', ')}.`
            : '';

    return `${drug}: ${item.text}${related}`;
});

watch(finding, () => {
    form.reset();
    form.clearErrors();
});

const open = computed({
    get: () => finding.value !== null,
    set: (value: boolean) => {
        if (!value) {
            finding.value = null;
        }
    },
});

function submit(): void {
    if (finding.value === null) {
        return;
    }

    form.submit(
        SafetyFindingController.continue({
            patient: props.patientId,
            safetyFinding: finding.value.finding.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                finding.value = null;
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="continue-finding-dialog">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Продолжить приём?</DialogTitle>
                    <DialogDescription v-if="finding">
                        {{ description }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="continue_justification"> Обоснование </Label>
                    <Textarea
                        id="continue_justification"
                        v-model="form.justification"
                        name="justification"
                        rows="4"
                        :maxlength="maxLength"
                        placeholder="Например: написал пациентке прекратить гидроксизин, контроль ЭКГ"
                    />
                    <p class="text-sm text-muted-foreground">
                        Обоснование сохранится в находке и в журнале аудита.
                    </p>
                    <InputError :message="form.errors.justification" />
                </div>

                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline">
                            Отмена
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="confirm-continue"
                    >
                        Продолжить приём
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
