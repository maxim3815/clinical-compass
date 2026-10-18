<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { House, Settings, UserPlus, Users } from '@lucide/vue';
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
import { home as doctorHome } from '@/routes/doctor';
import { index as invitations } from '@/routes/doctor/invitations';
import { index as patients } from '@/routes/doctor/patients';
import { home as patientHome } from '@/routes/patient';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

const page = usePage();
const { isCurrentOrParentUrl } = useCurrentUrl();
const { setOpenMobile } = useSidebar();

// На телефоне меню открывается поверх страницы: после перехода его нужно закрыть.
onUnmounted(router.on('navigate', () => setOpenMobile(false)));

const isDoctor = computed(() => page.props.auth.user.role === 'doctor');

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
                  isActive: isCurrentOrParentUrl(patients()),
              },
              {
                  title: 'Приглашения',
                  href: invitations(),
                  icon: UserPlus,
              },
              settings,
          ]
        : [
              {
                  title: 'Кабинет',
                  href: patientHome(),
                  icon: House,
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
