import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    // Число на пункте меню, например непрочитанные сообщения. Ноль не показывается.
    badge?: number;
    // Что считает число, для скринридера: «непрочитанных».
    badgeLabel?: string;
    // «attention» — число того, что ждёт решения врача, оно выделено сильнее.
    badgeTone?: 'default' | 'attention';
};
