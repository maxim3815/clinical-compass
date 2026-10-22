<script setup lang="ts">
import { Check, Copy, Share2 } from '@lucide/vue';
import { onMounted, ref, useId, watch } from 'vue';
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

onMounted(() => {
    canShare.value = typeof navigator.share === 'function';
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
    <div
        class="flex flex-col gap-3 rounded-xl border border-success-border bg-success-subtle p-4"
    >
        <p class="text-sm font-medium">
            <slot />
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
                    type="button"
                    class="flex-1 sm:flex-none"
                    @click="shareLink"
                >
                    <Share2 class="size-4" />
                    Отправить
                </Button>
                <Button
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
