<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import DoseController from '@/actions/App/Http/Controllers/Patient/DoseController';
import { Button } from '@/components/ui/button';
import type { Dose } from '@/types';

const props = defineProps<{
    dose: Dose;
}>();

const emit = defineEmits<{
    // Открыть форму отметки: «Принял в …», «Не принял» или «Изменить».
    mark: [status: 'taken' | 'skipped'];
}>();

const isMarked = computed(
    () => props.dose.status === 'taken' || props.dose.status === 'skipped',
);

const statusClass = computed(() => {
    if (props.dose.status === 'taken') {
        return props.dose.taken_late ? 'text-amber-700' : 'text-emerald-700';
    }

    return 'text-destructive';
});

const takeForm = useForm({ status: 'taken' });
const cancelForm = useForm({});

function take(): void {
    takeForm.submit(DoseController.update(props.dose.id), {
        preserveScroll: true,
    });
}

function cancel(): void {
    cancelForm.submit(DoseController.cancel(props.dose.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="flex flex-col gap-2 break-words" data-test="dose">
        <div>
            <p class="font-medium">{{ dose.drug }}</p>
            <p class="text-sm">{{ dose.dose }}</p>
            <p v-if="dose.instruction" class="text-sm text-muted-foreground">
                {{ dose.instruction }}
            </p>
        </div>

        <div v-if="dose.status_text" class="flex flex-col gap-1 text-sm">
            <p class="font-medium" :class="statusClass" data-test="dose-status">
                {{ dose.status_text }}
            </p>
            <p v-if="dose.skip_comment" class="text-muted-foreground">
                {{ dose.skip_comment }}
            </p>
            <p v-if="dose.marked_late" class="text-xs text-muted-foreground">
                Отмечено позже, {{ dose.marked_late }}
            </p>
            <p
                v-if="dose.missed_dose"
                class="rounded-md bg-muted p-2"
                data-test="missed-dose"
            >
                {{ dose.missed_dose }}
            </p>
        </div>

        <div v-if="isMarked" class="flex flex-wrap gap-2">
            <Button
                size="sm"
                variant="outline"
                data-test="edit-dose-mark"
                @click="
                    emit(
                        'mark',
                        dose.status === 'skipped' ? 'skipped' : 'taken',
                    )
                "
            >
                Изменить
            </Button>
            <Button
                size="sm"
                variant="ghost"
                :disabled="cancelForm.processing"
                data-test="cancel-dose-mark"
                @click="cancel"
            >
                Отменить отметку
            </Button>
        </div>

        <div v-else-if="dose.due" class="flex flex-wrap gap-2">
            <Button
                size="sm"
                :disabled="takeForm.processing"
                data-test="take-dose"
                @click="take"
            >
                Принял
            </Button>
            <Button
                size="sm"
                variant="outline"
                data-test="take-dose-at"
                @click="emit('mark', 'taken')"
            >
                Принял в …
            </Button>
            <Button
                size="sm"
                variant="outline"
                data-test="skip-dose"
                @click="emit('mark', 'skipped')"
            >
                Не принял
            </Button>
        </div>

        <p v-else class="text-xs text-muted-foreground">
            Отметить приём можно с {{ dose.due_from }}.
        </p>
    </div>
</template>
