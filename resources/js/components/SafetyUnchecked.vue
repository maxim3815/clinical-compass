<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, CircleHelp } from '@lucide/vue';
import { computed } from 'vue';
import type { InertiaLinkProps } from '@inertiajs/vue3';

const props = defineProps<{
    unchecked: string[];
    unknownDrugs: string[];
    // Список видно сразу: в форме назначения врач решает сейчас. В карточке он свёрнут.
    open?: boolean;
    // Куда внести недостающие данные.
    profileHref?: InertiaLinkProps['href'];
}>();

// Два названия и меньше видны строкой сразу. Длинный список в карточке свёрнут: в строке
// первое название и сколько ещё, в форме открыт, и в строке только число.
const short = computed(() => props.unchecked.length <= 2);
const preview = computed(() =>
    props.open
        ? String(props.unchecked.length)
        : `${props.unchecked[0]} и ещё ${props.unchecked.length - 1}`,
);
</script>

<template>
    <div
        v-if="unchecked.length > 0 || unknownDrugs.length > 0"
        class="flex flex-col gap-1 text-sm text-muted-foreground"
        data-test="safety-unchecked"
    >
        <p v-if="short && unchecked.length > 0" class="flex gap-1.5">
            <CircleHelp class="mt-0.5 size-4 shrink-0" />
            <span
                ><span class="font-medium text-foreground">Не проверено</span>,
                нет данных: {{ unchecked.join(', ') }}.
                <Link
                    v-if="profileHref"
                    :href="profileHref"
                    class="text-primary underline underline-offset-4"
                    >Внести в клинический профиль</Link
                ></span
            >
        </p>
        <details v-else-if="unchecked.length > 0" :open="open" class="group">
            <summary
                class="flex w-fit cursor-pointer items-start gap-1.5 rounded-sm hover:text-foreground pointer-coarse:min-h-11"
            >
                <CircleHelp class="mt-0.5 size-4 shrink-0" />
                <span class="line-clamp-2"
                    ><span class="font-medium text-foreground"
                        >Не проверено</span
                    >, нет данных: {{ preview }}</span
                >
                <ChevronDown
                    class="mt-0.5 size-4 shrink-0 transition-transform group-open:rotate-180"
                />
            </summary>
            <p class="mt-1 pl-5.5">
                {{ unchecked.join(', ') }}.
                <Link
                    v-if="profileHref"
                    :href="profileHref"
                    class="text-primary underline underline-offset-4"
                    >Внести в клинический профиль</Link
                >
            </p>
        </details>
        <p v-for="drug in unknownDrugs" :key="drug" class="flex gap-1.5">
            <CircleHelp class="mt-0.5 size-4 shrink-0" />
            <span
                >«{{ drug }}»: препарата нет в справочнике, сочетания с ним не
                проверены.</span
            >
        </p>
    </div>
</template>
