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
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import type { Dose, SelectOption } from '@/types';

defineProps<{
    reasons: SelectOption[];
}>();

// Приём, который отмечает пациент. Пока его нет, диалог закрыт.
const dose = defineModel<Dose | null>('dose', { required: true });

// С какой отметки открыть форму: «Принял в …» или «Не принял».
const status = defineModel<'taken' | 'skipped'>('status', { required: true });

const form = useForm({
    status: 'taken' as 'taken' | 'skipped',
    time: '',
    reason: '',
    comment: '',
});

watch(dose, (value) => {
    form.clearErrors();

    if (value !== null) {
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
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="dose-mark-dialog">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Отметка приёма</DialogTitle>
                    <DialogDescription v-if="dose">
                        {{ dose.drug }}, {{ dose.dose }}. Приём {{ dose.day }} в
                        {{ dose.time }}.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid grid-cols-2 gap-2" role="group">
                    <Button
                        type="button"
                        :variant="
                            form.status === 'taken' ? 'default' : 'outline'
                        "
                        :aria-pressed="form.status === 'taken'"
                        @click="form.status = 'taken'"
                    >
                        Принял
                    </Button>
                    <Button
                        type="button"
                        :variant="
                            form.status === 'skipped' ? 'default' : 'outline'
                        "
                        :aria-pressed="form.status === 'skipped'"
                        @click="form.status = 'skipped'"
                    >
                        Не принял
                    </Button>
                </div>

                <div v-if="form.status === 'taken'" class="grid gap-2">
                    <Label for="dose_time">Во сколько приняли</Label>
                    <Input
                        id="dose_time"
                        v-model="form.time"
                        type="time"
                        name="time"
                        class="w-32"
                    />
                    <InputError :message="form.errors.time" />
                </div>

                <template v-else>
                    <div class="grid gap-2">
                        <Label for="skip_reason">Причина</Label>
                        <NativeSelect
                            id="skip_reason"
                            v-model="form.reason"
                            name="reason"
                        >
                            <option value="" disabled>Выберите причину</option>
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
                        <Label for="skip_comment">
                            Комментарий{{
                                form.reason === 'other'
                                    ? ''
                                    : ' (необязательно)'
                            }}
                        </Label>
                        <Textarea
                            id="skip_comment"
                            v-model="form.comment"
                            name="comment"
                            rows="3"
                        />
                        <InputError :message="form.errors.comment" />
                    </div>
                </template>

                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="save-dose-mark"
                    >
                        Сохранить
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
