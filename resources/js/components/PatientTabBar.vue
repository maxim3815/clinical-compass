<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarCheck, MessageCircle, Pill, Settings } from '@lucide/vue';
import { computed } from 'vue';
import { home, medications } from '@/routes/patient';
import { show as chat } from '@/routes/patient/chat';
import { edit as settings } from '@/routes/profile';
import type { NavItem } from '@/types';

const page = usePage();

// Раздел определяем по странице, а не по адресу: так вложенные страницы
// («Самочувствие», «Время приёма», «Свой препарат», все настройки) подсвечивают свою вкладку.
const tabs = computed<(NavItem & { name: string })[]>(() => [
    {
        name: 'today',
        title: 'Сегодня',
        href: home(),
        icon: CalendarCheck,
        isActive: [
            'patient/Home',
            'patient/Wellbeing',
            'patient/Questionnaire',
            'patient/QuestionnaireSent',
        ].includes(page.component),
    },
    {
        name: 'medications',
        title: 'Препараты',
        href: medications(),
        icon: Pill,
        isActive: [
            'patient/Medications',
            'patient/MedicationForm',
            'patient/IntakeTimes',
        ].includes(page.component),
    },
    {
        name: 'chat',
        title: 'Чат',
        href: chat(),
        icon: MessageCircle,
        isActive: page.component === 'patient/Chat',
        badge: page.props.unreadMessages,
    },
    {
        name: 'settings',
        title: 'Настройки',
        href: settings(),
        icon: Settings,
        isActive: page.component.startsWith('settings/'),
    },
]);
</script>

<template>
    <nav
        aria-label="Разделы"
        class="fixed inset-x-0 bottom-0 z-40 border-t bg-card pb-[env(safe-area-inset-bottom)]"
        data-test="tab-bar"
    >
        <ul class="mx-auto grid h-(--tab-bar-height) max-w-xl grid-cols-4">
            <li v-for="tab in tabs" :key="tab.name">
                <Link
                    :href="tab.href"
                    class="group flex h-full flex-col items-center justify-center gap-1 text-base font-medium tracking-tight outline-none focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-ring focus-visible:outline-solid max-[21rem]:text-sm"
                    :class="
                        tab.isActive
                            ? 'text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-current="tab.isActive ? 'page' : undefined"
                    :data-test="`tab-${tab.name}`"
                >
                    <span
                        class="relative flex h-8 w-14 items-center justify-center rounded-full transition-colors"
                        :class="
                            tab.isActive
                                ? 'bg-secondary'
                                : 'group-hover:bg-muted'
                        "
                    >
                        <component :is="tab.icon" class="size-6" />
                        <span
                            v-if="tab.badge"
                            class="absolute -top-1.5 right-1 flex h-6 min-w-6 items-center justify-center rounded-full bg-primary px-1.5 text-base leading-none font-semibold text-primary-foreground tabular-nums ring-2 ring-card"
                            aria-hidden="true"
                            data-test="nav-badge"
                        >
                            {{ tab.badge }}
                        </span>
                    </span>
                    <!--
                        Подпись 16 px и на экране 360 px. Полужирной активную не делаем: «Препараты»
                        тогда не помещается. Уже 336 px и с крупным шрифтом подпись меньше
                        или переносится по слогам.
                    -->
                    <span
                        class="max-w-full text-center leading-tight hyphens-auto"
                        >{{ tab.title }}</span
                    >
                    <span v-if="tab.badge" class="sr-only">
                        (непрочитанных: {{ tab.badge }})
                    </span>
                </Link>
            </li>
        </ul>
    </nav>
</template>
