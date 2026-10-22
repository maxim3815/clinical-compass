<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger, useSidebar } from '@/components/ui/sidebar';
import { edit as settings } from '@/routes/profile';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const { isMobile, state } = useSidebar();

// Страницы настроек передают только свой раздел, а в меню врач выбрал «Настройки».
const crumbs = computed<BreadcrumbItem[]>(() =>
    page.component.startsWith('settings/')
        ? [{ title: 'Настройки', href: settings() }, ...props.breadcrumbs]
        : props.breadcrumbs,
);

// Когда меню скрыто (телефон или свёрнутое меню), его счётчиков не видно:
// точка на кнопке меню говорит, что там что-то ждёт врача.
const hasNews = computed(
    () =>
        (isMobile.value || state.value === 'collapsed') &&
        page.props.patientSignals + page.props.unreadMessages > 0,
);
</script>

<template>
    <header
        class="flex h-14 shrink-0 items-center gap-2 border-b bg-background px-4 md:px-6"
    >
        <div class="relative -ml-2">
            <SidebarTrigger
                class="size-10 text-muted-foreground hover:text-foreground pointer-coarse:size-11"
                :aria-describedby="hasNews ? 'menu-news-text' : undefined"
            />
            <span
                v-if="hasNews"
                class="pointer-events-none absolute top-2 right-2 size-2.5 rounded-full ring-2 ring-background"
                :class="
                    page.props.urgentAlerts.length > 0
                        ? 'bg-danger'
                        : page.props.patientSignals > 0
                          ? 'bg-warning'
                          : 'bg-primary'
                "
                aria-hidden="true"
                data-test="menu-news"
            />
            <span v-if="hasNews" id="menu-news-text" class="sr-only">
                В меню есть пациенты, которые требуют внимания, или
                непрочитанные сообщения
            </span>
        </div>
        <Breadcrumbs v-if="crumbs.length > 0" :breadcrumbs="crumbs" />
    </header>
</template>
