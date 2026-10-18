<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'Ссылка недействительна',
        description:
            'Ссылка неверная, устарела или уже использована. Попросите у врача новую ссылку.',
    },
});
</script>

<template>
    <Head title="Ссылка недействительна" />

    <p class="text-center text-sm text-muted-foreground">
        Уже зарегистрированы?
        <TextLink :href="login()">Войти</TextLink>
    </p>
</template>
