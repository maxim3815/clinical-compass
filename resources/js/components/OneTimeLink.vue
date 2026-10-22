<script setup lang="ts">
import { Check, CircleCheck, Copy, Share2 } from '@lucide/vue';
import { nextTick, onMounted, ref, useId, useTemplateRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    url: string;
    label: string;
}>();

const inputId = useId();
const copied = ref(false);
const selected = ref(false);

// Новая ссылка: кнопка снова предлагает скопировать.
watch(
    () => props.url,
    () => {
        copied.value = false;
        selected.value = false;
    },
);

// На телефоне ссылку удобнее сразу отправить в мессенджер через системное меню «Поделиться».
const canShare = ref(false);

const shareButton = useTemplateRef<{ $el: HTMLElement }>('shareButton');
const copyButton = useTemplateRef<{ $el: HTMLElement }>('copyButton');

// Ссылка появилась: фокус на действие с ней, чтобы с клавиатуры и скринридером не искать её.
onMounted(async () => {
    canShare.value = typeof navigator.share === 'function';
    await nextTick();
    (shareButton.value ?? copyButton.value)?.$el.focus();
});

async function shareLink(): Promise<void> {
    try {
        await navigator.share({ url: props.url });
    } catch {
        // Врач закрыл меню «Поделиться»: ссылка остаётся на экране.
    }
}

async function copyLink(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.url);
        copied.value = true;
    } catch {
        // Буфер обмена недоступен (например, по http не с localhost): выделяем ссылку для ручного копирования.
        const input = document.getElementById(inputId);

        if (input instanceof HTMLInputElement) {
            input.select();
            selected.value = true;
        }
    }
}
</script>

<template>
    <!-- Белая подложка: рамки поля и кнопки на ней не бледнее 3:1. -->
    <div
        class="flex flex-col gap-3 rounded-xl border border-success-border bg-card p-4 shadow-xs"
    >
        <p class="flex gap-2 text-sm font-medium" role="status">
            <CircleCheck class="mt-0.5 size-4 shrink-0 text-success" />
            <span><slot /></span>
        </p>
        <!-- На телефоне поле во всю ширину, кнопки под ним. -->
        <div class="flex flex-col gap-2 sm:flex-row">
            <Input
                :id="inputId"
                :model-value="url"
                readonly
                :aria-label="label"
                @focus="($event.target as HTMLInputElement).select()"
            />
            <div class="flex gap-2">
                <Button
                    v-if="canShare"
                    ref="shareButton"
                    type="button"
                    class="flex-1 sm:flex-none"
                    @click="shareLink"
                >
                    <Share2 class="size-4" />
                    Отправить
                </Button>
                <Button
                    ref="copyButton"
                    type="button"
                    variant="outline"
                    class="flex-1 sm:flex-none"
                    @click="copyLink"
                >
                    <Check v-if="copied" class="size-4" />
                    <Copy v-else class="size-4" />
                    {{ copied ? 'Скопировано' : 'Скопировать' }}
                </Button>
            </div>
        </div>
        <!-- Итог копирования озвучивается: кнопка меняет только подпись. -->
        <p class="sr-only" aria-live="polite">
            {{ copied ? 'Ссылка скопирована' : '' }}
        </p>
        <p v-if="selected" class="text-sm text-muted-foreground">
            Ссылка выделена, скопируйте её вручную (Ctrl+C или ⌘C).
        </p>
    </div>
</template>
