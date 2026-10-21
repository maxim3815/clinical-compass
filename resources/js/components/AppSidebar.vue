<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    CalendarCheck,
    MessageCircle,
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
import { useRealtime } from '@/composables/useRealtime';
import { home as doctorHome } from '@/routes/doctor';
import { index as conversations } from '@/routes/doctor/conversations';
import { index as drugs } from '@/routes/doctor/drugs';
import { index as invitations } from '@/routes/doctor/invitations';
import { index as patients } from '@/routes/doctor/patients';
import { index as rules } from '@/routes/doctor/rules';
import { home as patientHome, medications } from '@/routes/patient';
import { show as patientChat } from '@/routes/patient/chat';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

const page = usePage();
const { currentUrl, isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const { setOpenMobile } = useSidebar();

// На телефоне меню открывается поверх страницы: после перехода его нужно закрыть.
onUnmounted(router.on('navigate', () => setOpenMobile(false)));

const isDoctor = computed(() => page.props.auth.user.role === 'doctor');

// Счётчик непрочитанного приходит с каждой страницей, а между переходами — событием.
useRealtime<{ count: number }>(
    `App.Models.User.${page.props.auth.user.id}`,
    'unread.changed',
    (event) => router.replaceProp('unreadMessages', event.count),
);

// Счётчик пациентов, которые требуют внимания врача, обновляется так же.
useRealtime<{ count: number }>(
    `App.Models.User.${page.props.auth.user.id}`,
    'patient-signals.changed',
    (event) => router.replaceProp('patientSignals', event.count),
);

// Диалог с пациентом относится к «Сообщениям», а не к «Пациентам».
const isDoctorChat = computed(() =>
    /^\/doctor\/patients\/\d+\/chat$/.test(currentUrl.value),
);

const cabinet = computed(() => (isDoctor.value ? doctorHome() : patientHome()));

const settings: NavItem = {
    title: 'Настройки',
    href: editProfile(),
    icon: Settings,
};

const mainNavItems = computed<NavItem[]>(() =>
    isDoctor.value
        ? [
              {
                  title: 'Пациенты',
                  href: patients(),
                  icon: Users,
                  // Карточка пациента тоже относится к разделу.
                  isActive:
                      isCurrentOrParentUrl(patients()) && !isDoctorChat.value,
                  badge: page.props.patientSignals,
                  badgeLabel: 'требуют внимания',
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
              settings,
          ]
        : [
              {
                  title: 'Сегодня',
                  href: patientHome(),
                  icon: CalendarCheck,
              },
              {
                  title: 'Мои препараты',
                  href: medications(),
                  icon: Pill,
              },
              {
                  title: 'Чат с врачом',
                  href: patientChat(),
                  icon: MessageCircle,
                  badge: page.props.unreadMessages,
              },
              settings,
          ],
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="cabinet">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
