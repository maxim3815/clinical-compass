<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { unsubscribeBeforeLogout } from '@/lib/push';
import { home, logout } from '@/routes';

defineOptions({
    layout: {
        title: 'Вы уже вошли',
        description:
            'Эта ссылка открывается без входа в учётную запись. Выйдите и откройте ссылку снова.',
    },
});

const leaving = ref(false);

// Ссылку открывают на чужом устройстве: пуши прежнего пользователя сюда больше не приходят.
async function leave(): Promise<void> {
    leaving.value = true;
    await unsubscribeBeforeLogout();
    router.visit(logout());
}
</script>

<template>
    <Head title="Вы уже вошли" />

    <div class="flex flex-col gap-3">
        <Button :disabled="leaving" data-test="logout-button" @click="leave">
            Выйти
        </Button>
        <Button variant="outline" as-child>
            <Link :href="home()">Вернуться в кабинет</Link>
        </Button>
    </div>
</template>
