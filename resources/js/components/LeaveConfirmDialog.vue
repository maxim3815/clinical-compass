<script setup lang="ts">
import { computed, useTemplateRef } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    // Куда уходят; пока null, окно закрыто.
    pending: string | null;
    // Что пропадёт: «Введённое», «Отмеченные значения фактов».
    what?: string;
}>();

const emit = defineEmits<{
    leave: [];
    stay: [];
}>();

const open = computed({
    get: () => props.pending !== null,
    set: (value: boolean) => {
        if (!value) {
            emit('stay');
        }
    },
});

// Как в окне выхода: главная кнопка и фокус — «Остаться».
const stayButton = useTemplateRef<{ $el: HTMLElement }>('stayButton');

function focusStay(event: Event): void {
    event.preventDefault();
    stayButton.value?.$el.focus();
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            data-test="leave-confirm-dialog"
            @open-auto-focus="focusStay"
        >
            <DialogHeader>
                <DialogTitle>Уйти без сохранения?</DialogTitle>
                <DialogDescription class="text-base">
                    {{ what ?? 'Введённое' }} не сохранится.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    size="lg"
                    data-test="confirm-leave"
                    @click="emit('leave')"
                >
                    Уйти
                </Button>
                <Button
                    ref="stayButton"
                    type="button"
                    size="lg"
                    @click="emit('stay')"
                >
                    Остаться
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
