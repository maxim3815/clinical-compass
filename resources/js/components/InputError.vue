<script setup lang="ts">
defineProps<{
    message?: string;
    // Поле ссылается на ошибку через aria-describedby, чтобы скринридер её прочитал.
    id?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p
            :id="id"
            class="text-sm font-medium text-destructive"
            role="alert"
            data-slot="input-error"
        >
            {{ message }}
        </p>
    </div>
</template>
