<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { index, show } from '@/routes/doctor/drugs';

type DrugRow = {
    code: string;
    name: string;
    inn: string;
    dosage_form: string;
    strengths: string[];
};

defineProps<{
    drugs: DrugRow[];
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
</script>

<template>
    <Head title="Препараты" />

    <div class="@container flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Препараты</h1>
            <p class="text-muted-foreground">
                Препараты, которые можно назначить. Сведения взяты из инструкций
                в ГРЛС, ссылка на документ есть в карточке препарата.
            </p>
        </header>

        <p
            v-if="drugs.length === 0"
            class="rounded-xl border border-dashed bg-card p-6 text-muted-foreground"
            data-test="drugs-empty"
        >
            Справочник пока пуст.
        </p>

        <ul v-else class="grid gap-3 @2xl:grid-cols-2">
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
    </div>
</template>
