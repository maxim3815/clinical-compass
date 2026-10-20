<script setup lang="ts">
import { Share, SquarePlus } from '@lucide/vue';
</script>

<template>
    <ol
        class="flex list-decimal flex-col gap-1 pl-5 text-sm"
        data-test="install-ios-steps"
    >
        <li>
            Нажмите «Поделиться»
            <Share
                class="inline size-4 align-text-bottom"
                aria-hidden="true"
            />.
        </li>
        <li>
            Выберите «На экран „Домой“»
            <SquarePlus
                class="inline size-4 align-text-bottom"
                aria-hidden="true"
            />.
        </li>
        <li>Нажмите «Добавить».</li>
    </ol>
</template>
