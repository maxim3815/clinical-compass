<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ConsentText from '@/components/ConsentText.vue';

defineOptions({
    layout: {
        title: 'Согласие на обработку персональных данных',
    },
});
</script>

<template>
    <Head title="Согласие на обработку персональных данных" />

    <ConsentText />
</template>
