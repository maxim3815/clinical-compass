<script setup lang="ts">
import AlertBanner from '@/components/AlertBanner.vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <!-- С клавиатуры можно сразу перейти к странице, не проходя всё меню. -->
    <a
        href="#content"
        class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-card focus:px-4 focus:py-2 focus:font-medium focus:shadow-lg focus:outline-2 focus:outline-ring focus:outline-solid"
    >
        Перейти к содержимому
    </a>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            id="content"
            variant="sidebar"
            class="min-w-0 overflow-x-clip outline-none"
            tabindex="-1"
        >
            <!-- Красная плашка алертов закреплена вместе с шапкой: новый алерт виден и внизу страницы. -->
            <div class="sticky top-0 z-20">
                <AppSidebarHeader :breadcrumbs="breadcrumbs" />
                <AlertBanner />
            </div>
            <slot />
        </AppContent>
        <Toaster container-aria-label="Уведомления" />
    </AppShell>
</template>
