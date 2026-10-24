<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import ReferralController from '@/actions/App/Http/Controllers/Doctor/ReferralController';
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
import type { ReferralCategory } from '@/types';

/**
 * Не направлять, хотя система подсказывает направление: почему — обязательно.
 */
const props = defineProps<{
    patientId: number;
    suggestion: ReferralCategory;
    maxLength: number;
}>();

const emit = defineEmits<{
    declined: [];
    closeAutoFocus: [event: Event];
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm<{ comment: string; suggested: ReferralCategory | null }>({
    comment: '',
    suggested: null,
});

watch(
    open,
    (value) => {
        if (value) {
            form.defaults({ comment: '', suggested: props.suggestion });
            form.reset();
            form.clearErrors();
        }
    },
    { immediate: true },
);

function submit(): void {
    form.submit(ReferralController.decline(props.patientId), {
        preserveScroll: true,
        onSuccess: () => {
            emit('declined');
            open.value = false;
        },
        onError: () => document.getElementById('decline_comment')?.focus(),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            data-test="decline-dialog"
            @close-auto-focus="(event: Event) => emit('closeAutoFocus', event)"
        >
            <form
                class="flex flex-col gap-4"
                novalidate
                @submit.prevent="submit"
            >
                <DialogHeader>
                    <DialogTitle>Не направлять к психиатру</DialogTitle>
                    <DialogDescription>
                        {{
                            `Система подсказывает направление «${suggestion === 'required' ? 'обязательно' : 'желательно'}». Решение за вами, но запишите, почему не направляете.`
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="decline_comment"
                        >Комментарий (обязательно)</Label
                    >
                    <Textarea
                        id="decline_comment"
                        v-model="form.comment"
                        name="comment"
                        rows="3"
                        :maxlength="maxLength"
                        aria-required="true"
                        :aria-invalid="form.errors.comment ? true : undefined"
                        aria-describedby="decline_comment-hint decline_comment-error"
                        data-test="decline-comment"
                    />
                    <p
                        id="decline_comment-hint"
                        class="text-sm text-muted-foreground"
                    >
                        До {{ maxLength }} символов. Решение сохранится в
                        карточке и в журнале аудита.
                    </p>
                    <InputError
                        id="decline_comment-error"
                        :message="form.errors.comment ?? form.errors.suggested"
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
                        data-test="confirm-decline"
                    >
                        <Spinner v-if="form.processing" />
                        Не направлять
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
