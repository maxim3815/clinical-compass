<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ArrowUpDown, Search, UserPlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdherenceSignal from '@/components/AdherenceSignal.vue';
import Heading from '@/components/Heading.vue';
import ListPagination from '@/components/ListPagination.vue';
import PushBanner from '@/components/PushBanner.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useRealtime } from '@/composables/useRealtime';
import { formatAge } from '@/lib/plural';
import { index as invitations } from '@/routes/doctor/invitations';
import { index, show } from '@/routes/doctor/patients';
import type { AdherenceSignal as Signal, Paginated } from '@/types';

type PatientRow = {
    id: number;
    name: string;
    age: number | null;
    started_at: string;
    adherence: Signal;
};

// По доле за 7 дней: сначала самые низкие или самые высокие. null — по ФИО.
type Sort = 'adherence' | '-adherence' | null;

const props = defineProps<{
    patients: Paginated<PatientRow>;
    search: string;
    sort: Sort;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Пациенты',
                href: index(),
            },
        ],
    },
});

const page = usePage();
const query = ref(props.search);

// Сортировка сохраняет поиск, а поиск — сортировку.
const sorted = (sort: Sort) =>
    index({ query: { search: props.search || undefined, sort } });

const byName = computed(() => sorted(null));
const byAdherence = computed(() =>
    sorted(props.sort === 'adherence' ? '-adherence' : 'adherence'),
);

const adherenceAriaSort = computed(() =>
    props.sort === 'adherence'
        ? 'ascending'
        : props.sort === '-adherence'
          ? 'descending'
          : undefined,
);

function submit(): void {
    const search = query.value.trim();

    router.get(
        index.url(),
        { search: search || undefined, sort: props.sort ?? undefined },
        {
            preserveState: true,
            replace: true,
        },
    );
}

// Пациент отметил приём или scheduler отметил пропуск: доли и сигналы в строках меняются.
useRealtime(
    `App.Models.User.${page.props.auth.user.id}`,
    'adherence.changed',
    () => router.reload({ only: ['patients'] }),
);
</script>

<template>
    <Head title="Пациенты" />

    <div class="flex max-w-3xl flex-col gap-6 p-4">
        <Heading
            title="Пациенты"
            description="Пациенты, которых вы сейчас ведёте."
        />

        <PushBanner />

        <div
            v-if="patients.total === 0 && !search"
            class="flex flex-col items-start gap-4 rounded-lg border border-dashed p-6"
            data-test="patients-empty"
        >
            <p class="text-sm text-muted-foreground">
                Пациентов пока нет. Пригласите пациента: он зарегистрируется по
                ссылке и появится в этом списке.
            </p>
            <Button as-child>
                <Link :href="invitations()">
                    <UserPlus />
                    Пригласить пациента
                </Link>
            </Button>
        </div>

        <template v-else>
            <form role="search" class="flex gap-2" @submit.prevent="submit">
                <Input
                    v-model="query"
                    type="search"
                    name="search"
                    placeholder="Поиск по ФИО"
                    aria-label="Поиск по ФИО"
                />
                <Button type="submit" variant="outline">
                    <Search />
                    Найти
                </Button>
            </form>

            <p
                v-if="patients.total === 0"
                class="text-sm text-muted-foreground"
            >
                По запросу «{{ search }}» никого не нашли.
                <Link
                    :href="index()"
                    class="text-foreground underline underline-offset-4"
                >
                    Показать всех
                </Link>
            </p>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th
                                class="px-3 py-2 font-medium"
                                :aria-sort="
                                    sort === null ? 'ascending' : undefined
                                "
                            >
                                <Link
                                    :href="byName"
                                    preserve-scroll
                                    class="inline-flex items-center gap-1 hover:text-foreground"
                                    data-test="sort-name"
                                >
                                    ФИО
                                    <ArrowUp
                                        v-if="sort === null"
                                        class="size-3.5"
                                    />
                                </Link>
                            </th>
                            <th
                                class="px-3 py-2 font-medium"
                                :aria-sort="adherenceAriaSort"
                            >
                                <Link
                                    :href="byAdherence"
                                    preserve-scroll
                                    class="inline-flex items-center gap-1 whitespace-nowrap hover:text-foreground"
                                    data-test="sort-adherence"
                                >
                                    Приём за 7 дней
                                    <ArrowUp
                                        v-if="sort === 'adherence'"
                                        class="size-3.5"
                                    />
                                    <ArrowDown
                                        v-else-if="sort === '-adherence'"
                                        class="size-3.5"
                                    />
                                    <ArrowUpDown v-else class="size-3.5" />
                                </Link>
                            </th>
                            <th class="px-3 py-2 font-medium">Возраст</th>
                            <th class="px-3 py-2 font-medium">
                                Под наблюдением с
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="patient in patients.data"
                            :key="patient.id"
                            class="border-t"
                            :class="{
                                'bg-destructive/5': patient.adherence.raised,
                            }"
                            data-test="patient-row"
                            :data-signal="patient.adherence.raised || undefined"
                        >
                            <td class="px-3 py-2">
                                <Link
                                    :href="show(patient.id)"
                                    class="font-medium underline-offset-4 hover:underline"
                                >
                                    {{ patient.name }}
                                </Link>
                            </td>
                            <td class="px-3 py-2">
                                <AdherenceSignal :signal="patient.adherence" />
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{
                                    patient.age === null
                                        ? '—'
                                        : formatAge(patient.age)
                                }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ patient.started_at }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ListPagination :page="patients" />
        </template>
    </div>
</template>
