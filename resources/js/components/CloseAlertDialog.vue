<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AlertController from '@/actions/App/Http/Controllers/Doctor/AlertController';
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
import { alertTitle } from '@/lib/alerts';
import type { AlertDecisionOption, PatientAlert } from '@/types';

const props = defineProps<{
    patientId: number;
    decisions: AlertDecisionOption[];
    maxLength: number;
}>();

// Алерт, который врач закрывает. Пока его нет, диалог закрыт.
const alert = defineModel<PatientAlert | null>('alert', { required: true });

// Решение врач выбирает явно: заранее отмеченный вариант в спешке оставили бы.
const form = useForm<{ decision: string | null; comment: string }>({
    decision: null,
    comment: '',
});

const commentRequired = computed(
    () =>
        props.decisions.find((option) => option.value === form.decision)
            ?.comment_required ?? false,
);

watch(alert, () => {
    form.reset();
    form.clearErrors();
});

watch(
    () => form.decision,
    () => form.clearErrors('decision'),
);

const open = computed({
    get: () => alert.value !== null,
    set: (value: boolean) => {
        if (!value) {
            alert.value = null;
        }
    },
});

function focusError(): void {
    document
        .querySelector<HTMLElement>(
            '[data-test="close-alert-dialog"] [aria-invalid="true"]',
        )
        ?.focus();
}

// Закрытый алерт исчезает из блока вместе с кнопкой, которой открыли окно: фокус — на заголовок блока.
let closedAlert = false;

function returnFocus(event: Event): void {
    if (closedAlert) {
        event.preventDefault();
        closedAlert = false;
        document.getElementById('alerts-title')?.focus();
    }
}

function submit(): void {
    if (alert.value === null) {
        return;
    }

    if (form.decision === null) {
        form.setError('decision', 'Выберите решение.');
        document
            .querySelector<HTMLElement>(
                '[data-test="close-alert-dialog"] input[name="decision"]',
            )
            ?.focus();

        return;
    }

    form.submit(
        AlertController.close({
            patient: props.patientId,
            alert: alert.value.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                closedAlert = true;
                alert.value = null;
            },
            onError: focusError,
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            data-test="close-alert-dialog"
            @close-auto-focus="returnFocus"
        >
            <form
                class="flex flex-col gap-4"
                novalidate
                @submit.prevent="submit"
            >
                <DialogHeader>
                    <DialogTitle>Закрыть алерт</DialogTitle>
                    <DialogDescription v-if="alert">
                        {{
                            `${alertTitle(alert.rule, alert.trigger)}. Опросник заполнен ${alert.submitted_at}.`
                        }}
                    </DialogDescription>
                </DialogHeader>

                <fieldset
                    class="flex flex-col gap-2"
                    aria-describedby="close_alert_decision-error"
                >
                    <legend class="mb-2 text-sm font-medium">Решение</legend>
                    <div class="grid gap-2">
                        <label
                            v-for="decision in decisions"
                            :key="decision.value"
                            class="flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-input bg-card px-3 py-2 text-sm transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid pointer-coarse:min-h-11"
                        >
                            <input
                                v-model="form.decision"
                                type="radio"
                                name="decision"
                                :value="decision.value"
                                class="size-4 shrink-0 outline-none"
                                :aria-invalid="
                                    form.errors.decision ? true : undefined
                                "
                            />
                            {{ decision.label }}
                        </label>
                    </div>
                    <InputError
                        id="close_alert_decision-error"
                        :message="form.errors.decision"
                    />
                </fieldset>

                <div class="grid gap-2">
                    <Label for="close_alert_comment">
                        {{
                            commentRequired
                                ? 'Комментарий (обязательно): почему угрозы нет'
                                : 'Комментарий (необязательно)'
                        }}
                    </Label>
                    <Textarea
                        id="close_alert_comment"
                        v-model="form.comment"
                        name="comment"
                        rows="3"
                        :maxlength="maxLength"
                        :aria-required="commentRequired"
                        :aria-invalid="form.errors.comment ? true : undefined"
                        aria-describedby="close_alert_comment-hint close_alert_comment-error"
                    />
                    <p
                        id="close_alert_comment-hint"
                        class="text-sm text-muted-foreground"
                    >
                        До {{ maxLength }} символов. Решение и комментарий
                        сохранятся в карточке и в журнале аудита.
                    </p>
                    <InputError
                        id="close_alert_comment-error"
                        :message="form.errors.comment"
                    />
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
                        :aria-busy="form.processing"
                        data-test="confirm-close-alert"
                    >
                        <Spinner v-if="form.processing" />
                        Закрыть алерт
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
