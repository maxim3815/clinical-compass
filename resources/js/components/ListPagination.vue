<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

defineProps<{
    page: Paginated<unknown>;
}>();
</script>

<template>
    <nav
        v-if="page.last_page > 1"
        class="flex items-center justify-between gap-2"
        aria-label="Страницы списка"
    >
        <Button v-if="page.prev_page_url" variant="outline" size="sm" as-child>
            <Link :href="page.prev_page_url" preserve-scroll>
                <ChevronLeft />
                Назад
            </Link>
        </Button>
        <span v-else />

        <span class="text-sm text-muted-foreground">
            Страница {{ page.current_page }} из {{ page.last_page }}
        </span>

        <Button v-if="page.next_page_url" variant="outline" size="sm" as-child>
            <Link :href="page.next_page_url" preserve-scroll>
                Вперёд
                <ChevronRight />
            </Link>
        </Button>
        <span v-else />
    </nav>
</template>
