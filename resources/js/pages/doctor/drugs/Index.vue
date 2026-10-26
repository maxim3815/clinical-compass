<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { drugMatches } from '@/lib/drugSearch';
import { index, show } from '@/routes/doctor/drugs';

type DrugRow = {
    code: string;
    name: string;
    inn: string;
    dosage_form: string;
    strengths: string[];
};

// Препарат из списка препаратов от других врачей: группы для правил и почему он в списке.
type KnownDrugRow = {
    code: string;
    name: string;
    inn: string;
    trade_names: string[];
    groups: string[];
    reason: string;
};

const props = defineProps<{
    drugs: DrugRow[];
    known: KnownDrugRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Препараты',
                href: index(),
            },
        ],
    },
});

const query = ref('');
const shownKnown = computed(() =>
    props.known.filter((drug) => drugMatches(drug, query.value)),
);

// Из карточки пациента ссылка ведёт к препарату списка: он выделен. Inertia меняет адрес без
// перехода по якорю, поэтому :target не срабатывает.
const highlighted = ref('');

onMounted(async () => {
    highlighted.value = decodeURIComponent(window.location.hash.slice(1));

    // Фокус на выделенном препарате: экранный диктор начинает с него.
    if (highlighted.value.startsWith('known-')) {
        await nextTick();
        const card = document.getElementById(highlighted.value);
        card?.scrollIntoView({ block: 'start' });
        card?.focus({ preventScroll: true });
    }
});
</script>

<template>
    <Head title="Препараты" />

    <div class="@container flex w-full max-w-5xl flex-col gap-8 p-4 md:p-6">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Препараты</h1>
            <!-- Список длинный: на телефоне к нему иначе листать мимо справочника. -->
            <a
                v-if="known.length > 0"
                href="#known-title"
                class="w-fit text-sm text-primary underline underline-offset-4"
                >Препараты от других врачей · {{ known.length }}</a
            >
        </div>

        <section
            class="-mt-4 flex flex-col gap-3"
            aria-labelledby="catalog-title"
        >
            <div class="flex flex-col gap-1">
                <h2 id="catalog-title" class="text-lg font-semibold">
                    Справочник
                </h2>
                <p class="text-sm text-muted-foreground">
                    Препараты, которые можно назначить. Сведения взяты из
                    инструкций в ГРЛС, ссылка на документ есть в карточке
                    препарата.
                </p>
            </div>

            <p
                v-if="drugs.length === 0"
                class="rounded-xl border border-dashed bg-card p-6 text-muted-foreground"
                data-test="drugs-empty"
            >
                Справочник пока пуст.
            </p>

            <ul v-else class="grid grid-cols-1 gap-3 @2xl:grid-cols-2">
                <li
                    v-for="drug in drugs"
                    :key="drug.code"
                    class="relative flex flex-col gap-1 rounded-xl border bg-card p-4 text-sm shadow-xs transition-colors hover:border-primary/60 has-[a:focus-visible]:border-primary"
                    data-test="drug-row"
                >
                    <!-- Ссылка растянута на всю карточку. -->
                    <Link
                        :href="show(drug.code)"
                        class="text-base font-semibold underline-offset-4 after:absolute after:inset-0 after:rounded-xl hover:underline"
                    >
                        {{ drug.name }}
                    </Link>
                    <p class="text-muted-foreground">
                        {{ drug.inn }} · {{ drug.dosage_form }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Дозировки: </span
                        ><span class="font-medium">{{
                            drug.strengths.join(', ')
                        }}</span>
                    </p>
                </li>
            </ul>
        </section>

        <section
            v-if="known.length > 0"
            class="flex flex-col gap-3"
            aria-labelledby="known-title"
            data-test="known-drugs"
        >
            <div class="flex flex-col gap-1">
                <h2 id="known-title" class="scroll-mt-20 text-lg font-semibold">
                    Препараты от других врачей
                </h2>
                <p class="text-sm text-muted-foreground">
                    Их пациент добавляет сам, назначить их нельзя. Сочетание с
                    препаратами справочника проверяют правила справочника по
                    группам. Препараты этого списка между собой не проверяются:
                    их инструкций в приложении нет.
                </p>
            </div>

            <div class="relative max-w-sm">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="query"
                    type="search"
                    class="pl-9"
                    placeholder="Название или МНН"
                    aria-label="Найти в списке препаратов от других врачей"
                    data-test="known-search"
                />
            </div>
            <p
                class="text-sm text-muted-foreground"
                role="status"
                data-test="known-count"
            >
                <template v-if="query.trim() !== ''"
                    >Показано {{ shownKnown.length }} из
                    {{ known.length }}</template
                >
            </p>

            <p
                v-if="shownKnown.length === 0"
                class="rounded-xl border border-dashed bg-card p-4 text-sm text-muted-foreground"
            >
                В списке такого препарата нет.
            </p>

            <ul v-else class="grid grid-cols-1 gap-3 @2xl:grid-cols-2">
                <li
                    v-for="drug in shownKnown"
                    :id="`known-${drug.code}`"
                    :key="drug.code"
                    :tabindex="
                        highlighted === `known-${drug.code}` ? -1 : undefined
                    "
                    class="flex min-w-0 scroll-mt-20 flex-col gap-2 rounded-xl border bg-card p-4 text-sm shadow-xs outline-none"
                    :class="{
                        'border-primary ring-2 ring-primary/30':
                            highlighted === `known-${drug.code}`,
                    }"
                    :aria-current="
                        highlighted === `known-${drug.code}`
                            ? 'true'
                            : undefined
                    "
                    data-test="known-drug"
                >
                    <div>
                        <h3
                            class="text-base font-semibold break-words hyphens-auto"
                        >
                            {{ drug.name }}
                        </h3>
                        <p
                            v-if="drug.trade_names.length > 0"
                            class="text-muted-foreground"
                        >
                            {{ drug.trade_names.join(', ') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="group in drug.groups"
                            :key="group"
                            variant="secondary"
                            class="h-auto text-left whitespace-normal"
                        >
                            {{ group }}
                        </Badge>
                    </div>
                    <p>{{ drug.reason }}</p>
                </li>
            </ul>
        </section>
    </div>
</template>
