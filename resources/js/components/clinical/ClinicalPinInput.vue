<script setup lang="ts">
import { ref } from 'vue';

interface Props {
    modelValue: string;
    length?: number;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    length: 4,
    disabled: false,
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'complete', value: string): void;
}>();

const inputs = ref<HTMLInputElement[]>([]);

function getDigit(index: number): string {
    return props.modelValue[index] || '';
}

function handleInput(index: number, event: Event) {
    const target = event.target as HTMLInputElement;
    const val = target.value.replace(/\D/g, '').slice(-1);

    const chars = props.modelValue.split('');
    chars[index] = val;
    const newVal = chars.join('').slice(0, props.length);
    emit('update:modelValue', newVal);

    if (val && index < props.length - 1) {
        inputs.value[index + 1]?.focus();
    }

    if (newVal.length === props.length) {
        emit('complete', newVal);
    }
}

function handleKeyDown(index: number, event: KeyboardEvent) {
    if (event.key === 'Backspace' && !getDigit(index) && index > 0) {
        inputs.value[index - 1]?.focus();
    }
}

function handlePaste(event: ClipboardEvent) {
    event.preventDefault();
    const pasted =
        event.clipboardData
            ?.getData('text')
            .replace(/\D/g, '')
            .slice(0, props.length) || '';
    if (pasted) {
        emit('update:modelValue', pasted);
        if (pasted.length === props.length) {
            emit('complete', pasted);
            inputs.value[props.length - 1]?.focus();
        } else {
            inputs.value[pasted.length]?.focus();
        }
    }
}
</script>

<template>
    <div
        class="flex items-center justify-center gap-3 select-none"
        @paste="handlePaste"
    >
        <input
            v-for="i in length"
            :key="i"
            ref="inputs"
            type="text"
            inputmode="numeric"
            maxlength="1"
            :disabled="disabled"
            :value="getDigit(i - 1)"
            class="h-16 w-14 rounded-2xl border-2 border-[#E2E8F0] bg-white text-center text-2xl font-bold text-[#1E293B] shadow-sm transition-all duration-200 outline-none focus:border-[#235347] focus:ring-4 focus:ring-[#E8F2EC] disabled:bg-[#F8FAFC] disabled:opacity-50"
            @input="handleInput(i - 1, $event)"
            @keydown="handleKeyDown(i - 1, $event)"
        />
    </div>
</template>
