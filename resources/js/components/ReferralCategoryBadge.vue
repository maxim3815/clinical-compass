<script setup lang="ts">
import { CircleAlert, Info } from '@lucide/vue';
import { CHIP_ICON, DANGER_CHIP, WARNING_CHIP } from '@/lib/chips';
import { cn } from '@/lib/utils';
import type { ReferralCategory } from '@/types';

/**
 * Категория направления к психиатру. «Обязательно» и «желательно» различимы цветом (красная
 * и янтарная плашка), иконкой и начертанием: «ОБЯЗАТЕЛЬНО» жирным прописными.
 */
withDefaults(
    defineProps<{
        category: ReferralCategory;
        // Подпись перед категорией; пустая — только категория.
        prefix?: string;
        large?: boolean;
        // Решение уже принято: плашка бледная, но иконка и начертание те же.
        muted?: boolean;
    }>(),
    { prefix: 'Направление к психиатру', large: false, muted: false },
);
</script>

<template>
    <span
        :class="
            cn(
                category === 'required' ? DANGER_CHIP : WARNING_CHIP,
                large && 'px-3 py-2 text-base',
                muted && 'border-border bg-muted text-muted-foreground',
            )
        "
        :data-test="`referral-category-${category}`"
    >
        <CircleAlert
            v-if="category === 'required'"
            :class="cn(CHIP_ICON, large && 'size-5')"
            aria-hidden="true"
        />
        <Info
            v-else
            :class="cn(CHIP_ICON, large && 'size-5')"
            aria-hidden="true"
        />
        <span>
            <span v-if="prefix">{{ `${prefix}: ` }}</span>
            <strong
                v-if="category === 'required'"
                class="font-bold tracking-wide uppercase"
                >обязательно</strong
            >
            <span v-else class="font-medium">желательно</span>
        </span>
    </span>
</template>
