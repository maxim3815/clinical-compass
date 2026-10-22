<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    MessagesSquare,
    Pill,
    Settings,
    ShieldCheck,
    UserPlus,
    Users,
} from '@lucide/vue';
import { computed, onUnmounted } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useNavCounters } from '@/composables/useNavCounters';
import { home } from '@/routes/doctor';
import { index as conversations } from '@/routes/doctor/conversations';
import { index as drugs } from '@/routes/doctor/drugs';
import { index as invitations } from '@/routes/doctor/invitations';
import { index as patients } from '@/routes/doctor/patients';
import { index as rules } from '@/routes/doctor/rules';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

const page = usePage();
const { currentUrl, isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const { setOpenMobile } = useSidebar();

// На телефоне меню открывается поверх страницы: после перехода его нужно закрыть.
onUnmounted(router.on('navigate', () => setOpenMobile(false)));

useNavCounters();

// Диалог с пациентом относится к «Сообщениям», а не к «Пациентам».
const isDoctorChat = computed(() =>
    /^\/doctor\/patients\/\d+\/chat$/.test(currentUrl.value),
);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Пациенты',
        href: patients(),
        icon: Users,
        // Карточка пациента тоже относится к разделу.
        isActive: isCurrentOrParentUrl(patients()) && !isDoctorChat.value,
        badge: page.props.patientSignals,
        badgeLabel: 'требуют внимания',
        // Пока есть непринятый алерт, число красное, как плашка алерта.
        badgeTone: page.props.urgentAlerts.length > 0 ? 'danger' : 'attention',
    },
    {
        title: 'Сообщения',
        href: conversations(),
        icon: MessagesSquare,
        isActive: isCurrentUrl(conversations()) || isDoctorChat.value,
        badge: page.props.unreadMessages,
    },
    {
        title: 'Приглашения',
        href: invitations(),
        icon: UserPlus,
    },
    {
        title: 'Препараты',
        href: drugs(),
        icon: Pill,
        // Карточка препарата тоже относится к разделу.
        isActive: isCurrentOrParentUrl(drugs()),
    },
    {
        title: 'Правила',
        href: rules(),
        icon: ShieldCheck,
    },
    {
        title: 'Настройки',
        href: editProfile(),
        icon: Settings,
        isActive: page.component.startsWith('settings/'),
    },
]);
</script>

<template>
    <Sidebar collapsible="icon">
        <SidebarHeader class="p-3">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        class="hover:bg-transparent active:bg-transparent"
                        as-child
                    >
                        <Link :href="home()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter class="border-t border-sidebar-border p-3">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
