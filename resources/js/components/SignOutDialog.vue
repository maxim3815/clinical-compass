<script setup lang="ts">
import { ref, useTemplateRef } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';
import { signOut } from '@/lib/logout';

const open = defineModel<boolean>('open', { default: false });

const leaving = ref(false);
const stay = useTemplateRef<{ $el: HTMLElement }>('stay');

// Выход снимает подписку на пуши этого устройства: напоминания перестанут приходить.
// Поэтому переспрашиваем, а главная кнопка и фокус — у «Остаться».
async function leave(): Promise<void> {
    leaving.value = true;
    await signOut();
}

function focusStay(event: Event): void {
    event.preventDefault();
    stay.value?.$el.focus();
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="sign-out-dialog" @open-auto-focus="focusStay">
            <DialogHeader>
                <DialogTitle>Выйти из кабинета?</DialogTitle>
                <DialogDescription class="text-base">
                    Напоминания и уведомления на этом устройстве перестанут
                    приходить. Чтобы вернуться, понадобятся логин и пароль.
                </DialogDescription>
            </DialogHeader>
            <!-- На телефоне кнопки идут столбцом снизу вверх: «Остаться» оказывается сверху. -->
            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    size="lg"
                    :disabled="leaving"
                    data-test="confirm-logout"
                    @click="leave"
                >
                    <Spinner v-if="leaving" />
                    Выйти
                </Button>
                <DialogClose as-child>
                    <Button ref="stay" type="button" size="lg">Остаться</Button>
                </DialogClose>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
