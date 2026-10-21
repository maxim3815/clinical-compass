<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronLeft } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes/patient';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const name = usePage().props.name;

// На вложенной странице вместо названия приложения — путь назад в раздел.
const parent = computed(() =>
    props.breadcrumbs.length > 1 ? props.breadcrumbs.at(-2) : undefined,
);
</script>

<template>
    <header class="sticky top-0 z-30 border-b bg-background">
        <div class="mx-auto flex h-14 max-w-xl items-center px-2">
            <Link
                v-if="parent"
                :href="parent.href"
                class="flex h-11 items-center gap-1 rounded-lg pr-3 pl-1 text-base font-medium text-primary outline-none hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-solid"
                data-test="back-link"
            >
                <ChevronLeft class="size-6" />
                {{ parent.title }}
            </Link>
            <Link
                v-else
                :href="home()"
                class="flex h-11 items-center gap-2.5 rounded-lg px-2 outline-none focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-solid"
            >
                <span
                    class="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-5" />
                </span>
                <span class="text-base font-semibold text-foreground">{{
                    name
                }}</span>
            </Link>
        </div>
    </header>
</template>
