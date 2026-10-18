<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { House, Settings, UserPlus } from '@lucide/vue';
import { computed } from 'vue';
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
} from '@/components/ui/sidebar';
import { home as doctorHome } from '@/routes/doctor';
import { index as invitations } from '@/routes/doctor/invitations';
import { home as patientHome } from '@/routes/patient';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

const page = usePage();

const isDoctor = computed(() => page.props.auth.user.role === 'doctor');

const cabinet = computed(() => (isDoctor.value ? doctorHome() : patientHome()));

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Кабинет',
        href: cabinet.value,
        icon: House,
    },
    ...(isDoctor.value
        ? [
              {
                  title: 'Приглашения',
                  href: invitations(),
                  icon: UserPlus,
              },
          ]
        : []),
    {
        title: 'Настройки',
        href: editProfile(),
        icon: Settings,
    },
]);
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
