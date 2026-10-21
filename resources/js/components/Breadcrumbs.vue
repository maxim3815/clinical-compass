<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type Props = {
    breadcrumbs: BreadcrumbItemType[];
};

defineProps<Props>();
</script>

<template>
    <Breadcrumb class="min-w-0">
        <BreadcrumbList class="flex-nowrap">
            <!-- На телефоне — только раздел и текущая страница: иначе крошки не помещаются в строку. -->
            <template v-for="(item, index) in breadcrumbs" :key="index">
                <BreadcrumbItem
                    class="min-w-0"
                    :class="{ 'max-md:hidden': index < breadcrumbs.length - 2 }"
                >
                    <template v-if="index === breadcrumbs.length - 1">
                        <BreadcrumbPage class="truncate">{{
                            item.title
                        }}</BreadcrumbPage>
                    </template>
                    <template v-else>
                        <BreadcrumbLink as-child>
                            <Link :href="item.href" class="truncate">{{
                                item.title
                            }}</Link>
                        </BreadcrumbLink>
                    </template>
                </BreadcrumbItem>
                <BreadcrumbSeparator
                    v-if="index !== breadcrumbs.length - 1"
                    :class="{ 'max-md:hidden': index < breadcrumbs.length - 2 }"
                />
            </template>
        </BreadcrumbList>
    </Breadcrumb>
</template>
