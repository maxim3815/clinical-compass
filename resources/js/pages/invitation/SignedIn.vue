<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { home, logout } from '@/routes';

defineOptions({
    layout: {
        title: 'Вы уже вошли',
        description:
            'Ссылка-приглашение нужна для регистрации нового пациента. Чтобы зарегистрироваться по ней, выйдите из учётной записи и откройте ссылку снова.',
    },
});
</script>

<template>
    <Head title="Вы уже вошли" />

    <div class="flex flex-col gap-3">
        <Button as-child>
            <Link :href="logout()" as="button" data-test="logout-button">
                Выйти
            </Link>
        </Button>
        <Button variant="outline" as-child>
            <Link :href="home()">Вернуться в кабинет</Link>
        </Button>
    </div>
</template>
