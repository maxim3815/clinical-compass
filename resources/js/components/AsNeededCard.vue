<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AsNeededDoseController from '@/actions/App/Http/Controllers/Patient/AsNeededDoseController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import type { AsNeeded } from '@/types';

const props = defineProps<{
    prescription: AsNeeded;
}>();

const form = useForm({
    day: 'today' as 'today' | 'yesterday',
    time: '',
    confirmed: false,
});

// Форма «Принял в …» или предупреждение о лимите за сутки.
const dialog = ref<'time' | 'limit' | null>(null);

const open = computed({
    get: () => dialog.value !== null,
    set: (value: boolean) => {
        if (!value) {
            dialog.value = null;
            form.clearErrors();
        }
    },
});

// После правки поля прежняя ошибка уже не относится к введённому.
watch(
    () => [form.day, form.time],
    () => form.clearErrors('day', 'time'),
);

const limitReached = computed(
    () =>
        props.prescription.max_daily_doses !== null &&
        props.prescription.taken_today >= props.prescription.max_daily_doses,
);

function submit(confirmed = false): void {
    form.confirmed = confirmed;
    form.submit(AsNeededDoseController.store(props.prescription.id), {
        preserveScroll: true,
        onSuccess: () => {
            dialog.value = null;
            form.reset();
        },
        // Лимит за сутки исчерпан: пациент подтверждает запись.
        onError: (errors) => {
            if (errors.confirmed) {
                dialog.value = 'limit';
            }
        },
    });
}

function takeNow(): void {
    form.reset();
    form.clearErrors();
    submit();
}

function openTimeForm(): void {
    form.reset();
    form.clearErrors();
    dialog.value = 'time';
}

const cancelForm = useForm({});

function cancel(id: number): void {
    cancelForm.submit(AsNeededDoseController.destroy(id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <article
        class="flex flex-col gap-3 rounded-lg border p-4 break-words"
        data-test="as-needed"
    >
        <div>
            <h3 class="font-medium">{{ prescription.drug }}</h3>
            <p class="text-sm">{{ prescription.dose }}</p>
            <p
                v-if="prescription.instruction"
                class="text-sm text-muted-foreground"
            >
                {{ prescription.instruction }}
            </p>
        </div>

        <p
            class="text-sm"
            :class="limitReached ? 'text-amber-700' : ''"
            data-test="as-needed-counter"
        >
            Сегодня принято {{ prescription.taken_today
            }}<template v-if="prescription.max_daily_doses !== null">
                из {{ prescription.max_daily_doses }}</template
            >
        </p>

        <div class="flex flex-wrap gap-2">
            <Button
                size="sm"
                :disabled="form.processing"
                data-test="take-as-needed"
                @click="takeNow"
            >
                Принял
            </Button>
            <Button
                size="sm"
                variant="outline"
                data-test="take-as-needed-at"
                @click="openTimeForm"
            >
                Принял в …
            </Button>
        </div>

        <ul
            v-if="prescription.doses.length"
            class="flex flex-col gap-1 text-sm"
        >
            <li
                v-for="dose in prescription.doses"
                :key="dose.id"
                class="flex items-center justify-between gap-2"
                data-test="as-needed-dose"
            >
                <span>{{ dose.day }} в {{ dose.time }}</span>
                <Button
                    size="sm"
                    variant="ghost"
                    :disabled="cancelForm.processing"
                    @click="cancel(dose.id)"
                >
                    Отменить
                </Button>
            </li>
        </ul>

        <Dialog v-model:open="open">
            <DialogContent
                v-if="dialog === 'limit'"
                data-test="as-needed-limit-dialog"
            >
                <DialogHeader>
                    <DialogTitle>Записать ещё один приём?</DialogTitle>
                    <DialogDescription>
                        {{ form.errors.confirmed }} Если вы всё же приняли
                        препарат, запишите приём, чтобы врач это видел.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                    >
                        Не записывать
                    </Button>
                    <Button
                        type="button"
                        :disabled="form.processing"
                        data-test="confirm-as-needed"
                        @click="submit(true)"
                    >
                        Записать
                    </Button>
                </DialogFooter>
            </DialogContent>

            <DialogContent v-else data-test="as-needed-time-dialog">
                <form class="flex flex-col gap-4" @submit.prevent="submit()">
                    <DialogHeader>
                        <DialogTitle>Когда вы приняли препарат?</DialogTitle>
                        <DialogDescription>
                            {{ prescription.drug }}, {{ prescription.dose }}.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="flex flex-wrap gap-4">
                        <div class="grid gap-2">
                            <Label for="as_needed_day">День</Label>
                            <NativeSelect
                                id="as_needed_day"
                                v-model="form.day"
                                name="day"
                            >
                                <option value="today">Сегодня</option>
                                <option value="yesterday">Вчера</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-2">
                            <Label for="as_needed_time">Время</Label>
                            <Input
                                id="as_needed_time"
                                v-model="form.time"
                                type="time"
                                name="time"
                                class="w-32"
                            />
                        </div>
                    </div>
                    <InputError :message="form.errors.day" />
                    <InputError :message="form.errors.time" />

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="open = false"
                        >
                            Отмена
                        </Button>
                        <Button
                            type="submit"
                            :disabled="form.processing"
                            data-test="save-as-needed"
                        >
                            Записать
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </article>
</template>
