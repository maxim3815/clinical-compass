<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();

const { isCurrentUrl } = useCurrentUrl();

const isActive = (item: NavItem): boolean =>
    item.isActive ?? isCurrentUrl(item.href);

// В свёрнутом меню чисел не видно: подсказка у иконки называет их словами.
const tooltip = (item: NavItem): string =>
    item.badge
        ? `${item.title}, ${item.badgeLabel ?? 'непрочитанных'}: ${item.badge}`
        : item.title;
</script>

<template>
    <SidebarGroup class="px-3 py-1">
        <SidebarMenu class="gap-1">
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isActive(item)"
                    :tooltip="tooltip(item)"
                    class="relative h-10 gap-3 px-3 text-sm group-data-[collapsible=icon]:size-10! group-data-[collapsible=icon]:p-3! data-[active=true]:shadow-[inset_3px_0_0_var(--sidebar-ring)] [&>svg]:size-[1.125rem]"
                >
                    <Link
                        :href="item.href"
                        :aria-current="isActive(item) ? 'page' : undefined"
                    >
                        <component :is="item.icon" />
                        <!-- В свёрнутом меню вместо числа — точка на иконке. -->
                        <span
                            v-if="item.badge"
                            class="absolute top-1.5 right-1.5 hidden size-2.5 rounded-full ring-2 ring-sidebar group-data-[collapsible=icon]:block"
                            :class="
                                item.badgeTone === 'danger'
                                    ? 'bg-danger'
                                    : item.badgeTone === 'attention'
                                      ? 'bg-warning-subtle'
                                      : 'bg-sidebar-primary'
                            "
                            aria-hidden="true"
                            data-test="nav-dot"
                        />
                        <span>{{ item.title }}</span>
                        <span v-if="item.badge" class="sr-only">
                            ({{ item.badgeLabel ?? 'непрочитанных' }}:
                            {{ item.badge }})
                        </span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuBadge
                    v-if="item.badge"
                    class="right-2 h-5 min-w-5 rounded-full px-1.5 font-semibold peer-data-[size=default]/menu-button:top-2.5"
                    :class="
                        item.badgeTone === 'danger'
                            ? 'bg-danger-subtle text-danger peer-hover/menu-button:text-danger peer-data-[active=true]/menu-button:text-danger'
                            : item.badgeTone === 'attention'
                              ? 'bg-warning-subtle text-warning peer-hover/menu-button:text-warning peer-data-[active=true]/menu-button:text-warning'
                              : 'bg-sidebar-primary text-sidebar-primary-foreground peer-hover/menu-button:text-sidebar-primary-foreground peer-data-[active=true]/menu-button:text-sidebar-primary-foreground'
                    "
                    aria-hidden="true"
                    data-test="nav-badge"
                >
                    {{ item.badge }}
                </SidebarMenuBadge>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
