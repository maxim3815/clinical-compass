<script setup lang="ts">
import { computed } from 'vue';
import ReferralBasis from '@/components/ReferralBasis.vue';
import ReferralCategoryBadge from '@/components/ReferralCategoryBadge.vue';
import type { Referral, ReferralCategory } from '@/types';

/**
 * Карточка психиатра: по какому направлению терапевта пациент к нему пришёл.
 */
const props = defineProps<{
    referral: Referral;
}>();

function label(category: ReferralCategory | null): string {
    return category === 'required' ? '«обязательно»' : '«желательно»';
}

const from = computed(
    () =>
        `${props.referral.from.name}, ${(props.referral.from.specialty ?? 'терапевт').toLowerCase()}. Направление от ${props.referral.created_at}, принято ${props.referral.accepted_at}.`,
);

const decision = computed(() =>
    props.referral.suggested_category === null
        ? `Система направление не подсказывала, решение терапевта: ${label(props.referral.category)}.`
        : `Система подсказывала ${label(props.referral.suggested_category)}, решение терапевта: ${label(props.referral.category)}.`,
);
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
        aria-labelledby="accepted-referral-title"
        data-test="accepted-referral"
    >
        <h2 id="accepted-referral-title" class="text-lg font-semibold">
            Направление терапевта
        </h2>
        <ReferralCategoryBadge
            :category="referral.category"
            prefix=""
            class="self-start"
        />
        <p class="text-sm">{{ from }}</p>
        <p
            v-if="referral.suggested_category !== referral.category"
            class="text-sm font-medium"
        >
            {{ decision }}
        </p>
        <p v-if="referral.comment" class="text-sm">
            <span class="text-muted-foreground">Комментарий терапевта: </span>
            {{ referral.comment }}
        </p>
        <details v-if="referral.basis.length > 0" class="text-sm">
            <summary
                class="w-fit cursor-pointer rounded-sm text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid pointer-coarse:flex pointer-coarse:min-h-11 pointer-coarse:items-center"
            >
                Основание подсказки
            </summary>
            <ReferralBasis :matches="referral.basis" class="mt-2" />
        </details>
    </section>
</template>
