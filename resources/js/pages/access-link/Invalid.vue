<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
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

    <div class="flex flex-col gap-3">
        <p class="text-base text-muted-foreground">Уже зарегистрированы?</p>
        <Button variant="outline" size="lg" class="w-full" as-child>
            <Link :href="login()">Войти</Link>
        </Button>
    </div>
</template>
