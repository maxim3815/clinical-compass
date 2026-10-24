<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronLeft, LifeBuoy } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes/patient';
import { helpOpenedInApp } from '@/lib/helpNavigation';
import { show as help } from '@/routes/patient/help';
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
const name = page.props.name;

// Кнопка помощи видна на каждом экране пациента, кроме самого экрана помощи.
const onHelp = computed(() => page.component === 'patient/Help');

function goBack(): void {
    window.history.back();
}

function markOpenedInApp(): void {
    helpOpenedInApp.value = true;
}

// На вложенной странице вместо названия приложения — путь назад в раздел.
const parent = computed(() =>
    props.breadcrumbs.length > 1 ? props.breadcrumbs.at(-2) : undefined,
);
</script>

<template>
    <header class="sticky top-0 z-30 border-b bg-background">
        <div class="mx-auto flex h-14 max-w-xl items-center gap-2 px-2">
            <!-- С экрана помощи, открытого из приложения, — туда, где пациент был. -->
            <button
                v-if="onHelp && helpOpenedInApp"
                type="button"
                class="flex h-11 min-w-0 items-center gap-1 rounded-lg pr-2 pl-1 text-base font-medium text-primary outline-none hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-solid"
                data-test="back-link"
                @click="goBack"
            >
                <ChevronLeft class="size-6 shrink-0" />
                <span class="truncate">Назад</span>
            </button>
            <Link
                v-else-if="parent"
                :href="parent.href"
                class="flex h-11 min-w-0 items-center gap-1 rounded-lg pr-2 pl-1 text-base font-medium text-primary outline-none hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-solid"
                data-test="back-link"
            >
                <ChevronLeft class="size-6 shrink-0" />
                <span class="truncate">{{ parent.title }}</span>
            </Link>
            <Link
                v-else
                :href="home()"
                class="flex h-11 min-w-0 items-center gap-2.5 rounded-lg px-2 outline-none focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-solid"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-5" />
                </span>
                <!--
                    Рядом с кнопкой помощи полное название на телефоне не помещается: там
                    только логотип, название слышит скринридер.
                -->
                <span
                    class="truncate text-base font-semibold text-foreground"
                    :class="{ 'max-[28rem]:sr-only': !onHelp }"
                    >{{ name }}</span
                >
            </Link>
            <!--
                Одно касание с любого экрана, без «Вы уверены?»: врачу ничего не уходит, пока
                пациент сам не нажмёт «Сообщить врачу». Не красная: красного нет там, где нет
                опасности, а кнопка нужна и в просто тяжёлую минуту.
            -->
            <Link
                v-if="!onHelp"
                :href="help()"
                class="ml-auto flex h-11 shrink-0 items-center gap-1.5 rounded-full border border-input bg-card px-3 text-base font-semibold text-primary shadow-xs outline-none hover:border-primary/60 hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                data-test="help-button"
                @click="markOpenedInApp"
            >
                <LifeBuoy class="size-5 shrink-0" />
                Нужна помощь
            </Link>
        </div>
    </header>
</template>
