<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Search, UserPlus } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatAge } from '@/lib/plural';
import { index as invitations } from '@/routes/doctor/invitations';
import { index, show } from '@/routes/doctor/patients';
import type { Paginated } from '@/types';

type PatientRow = {
    id: number;
    name: string;
    age: number | null;
    started_at: string;
};

const props = defineProps<{
    patients: Paginated<PatientRow>;
    search: string;
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

const query = ref(props.search);

function submit(): void {
    const search = query.value.trim();

    router.get(index.url(), search ? { search } : {}, {
        preserveState: true,
        replace: true,
    });
}
</script>

<template>
    <Head title="Пациенты" />

    <div class="flex max-w-3xl flex-col gap-6 p-4">
        <Heading
            title="Пациенты"
            description="Пациенты, которых вы сейчас ведёте."
        />

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
                            <th class="px-3 py-2 font-medium">ФИО</th>
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
                            data-test="patient-row"
                        >
                            <td class="px-3 py-2">
                                <Link
                                    :href="show(patient.id)"
                                    class="font-medium underline-offset-4 hover:underline"
                                >
                                    {{ patient.name }}
                                </Link>
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

            <nav
                v-if="patients.last_page > 1"
                class="flex items-center justify-between gap-2"
                aria-label="Страницы списка"
            >
                <Button
                    v-if="patients.prev_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="patients.prev_page_url" preserve-scroll>
                        <ChevronLeft />
                        Назад
                    </Link>
                </Button>
                <span v-else />

                <span class="text-sm text-muted-foreground">
                    Страница {{ patients.current_page }} из
                    {{ patients.last_page }}
                </span>

                <Button
                    v-if="patients.next_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="patients.next_page_url" preserve-scroll>
                        Вперёд
                        <ChevronRight />
                    </Link>
                </Button>
                <span v-else />
            </nav>
        </template>
    </div>
</template>
