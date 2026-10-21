<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { signOut } from '@/lib/logout';
import { home } from '@/routes';

defineOptions({
    layout: {
        title: 'Вы уже вошли',
        description:
            'По этой ссылке регистрируются или задают новый пароль, а для этого нужно сначала выйти из кабинета. Выйдите и откройте ссылку снова.',
    },
});

const leaving = ref(false);

// Чей кабинет сейчас открыт: по нему человек решает, выходить или вернуться.
const name = usePage().props.auth.user.name;

// Ссылку открывают на чужом устройстве: пуши прежнего пользователя сюда больше не приходят.
async function leave(): Promise<void> {
    leaving.value = true;
    await signOut();
}
</script>

<template>
    <Head title="Вы уже вошли" />

    <p class="text-base" data-test="signed-in-as">
        Сейчас открыт кабинет: <span class="font-semibold">{{ name }}</span>
    </p>

    <!-- Главное — вернуться: выход отключает напоминания на этом устройстве. -->
    <div class="flex flex-col gap-3">
        <Button size="lg" as-child>
            <Link :href="home()">Вернуться в кабинет</Link>
        </Button>
        <Button
            variant="outline"
            size="lg"
            :disabled="leaving"
            data-test="logout-button"
            @click="leave"
        >
            <Spinner v-if="leaving" />
            Выйти
        </Button>
        <p class="text-base text-muted-foreground">
            После выхода напоминания и уведомления на этом устройстве перестанут
            приходить.
        </p>
    </div>
</template>
