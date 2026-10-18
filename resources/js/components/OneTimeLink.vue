<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { ref, useId, watch } from 'vue';
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
        class="flex flex-col gap-3 rounded-lg border border-green-600/40 bg-green-50 p-4 dark:bg-green-950/30"
    >
        <p class="text-sm font-medium">
            <slot />
        </p>
        <div class="flex gap-2">
            <Input
                :id="inputId"
                :model-value="url"
                readonly
                :aria-label="label"
                @focus="($event.target as HTMLInputElement).select()"
            />
            <Button type="button" variant="outline" @click="copyLink">
                <Check v-if="copied" class="size-4" />
                <Copy v-else class="size-4" />
                {{ copied ? 'Скопировано' : 'Скопировать' }}
            </Button>
        </div>
        <p v-if="selected" class="text-sm text-muted-foreground">
            Ссылка выделена, скопируйте её вручную (Ctrl+C или ⌘C).
        </p>
    </div>
</template>
