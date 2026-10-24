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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { AdverseEvent } from '@/types';

/**
 * Комментарий врача к просмотренному нежелательному явлению: что решили. Пустой убирает прежний.
 */
const props = defineProps<{
    patientId: number;
    maxLength: number;
}>();

// Запись, к которой врач пишет комментарий. Пока её нет, окно закрыто.
const event = defineModel<AdverseEvent | null>('event', { required: true });

const form = useForm<{ comment: string }>({ comment: '' });

watch(event, (value) => {
    form.defaults({ comment: value?.comment ?? '' });
    form.reset();
    form.clearErrors();
});

const open = computed({
    get: () => event.value !== null,
    set: (value: boolean) => {
        if (!value) {
            event.value = null;
        }
    },
});

function submit(): void {
    if (event.value === null) {
        return;
    }

    form.submit(
        AdverseEventController.comment({
            patient: props.patientId,
            adverseEvent: event.value.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                event.value = null;
            },
            onError: () =>
                document
                    .querySelector<HTMLElement>('#adverse_event_comment')
                    ?.focus(),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="adverse-event-comment-dialog">
            <form
                class="flex flex-col gap-4"
                novalidate
                @submit.prevent="submit"
            >
                <DialogHeader>
                    <DialogTitle>Комментарий</DialogTitle>
                    <DialogDescription v-if="event">
                        {{ event.term ?? event.name }}. Пациент комментарий не
                        видит.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="adverse_event_comment">Что решили</Label>
                    <Textarea
                        id="adverse_event_comment"
                        v-model="form.comment"
                        name="comment"
                        rows="3"
                        :maxlength="maxLength"
                        :aria-invalid="form.errors.comment ? true : undefined"
                        aria-describedby="adverse_event_comment-error"
                    />
                    <InputError
                        id="adverse_event_comment-error"
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
                        data-test="save-adverse-event-comment"
                    >
                        <Spinner v-if="form.processing" />
                        Сохранить
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
