<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
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

    <div class="flex max-w-4xl flex-col gap-6 p-4">
        <Heading
            title="Препараты"
            description="Справочник препаратов, которые можно назначить. Данные взяты из инструкций в ГРЛС, источник указан в карточке препарата."
        />

        <p
            v-if="drugs.length === 0"
            class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
            data-test="drugs-empty"
        >
            Справочник пока пуст.
        </p>

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-2 py-2 font-medium md:px-3">Название</th>
                        <th class="px-2 py-2 font-medium md:px-3">МНН</th>
                        <!-- На телефоне форма уступает место дозировкам. -->
                        <th
                            class="hidden px-2 py-2 font-medium md:table-cell md:px-3"
                        >
                            Форма
                        </th>
                        <th class="px-2 py-2 font-medium md:px-3">Дозировки</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="drug in drugs"
                        :key="drug.code"
                        class="border-t"
                        data-test="drug-row"
                    >
                        <td class="px-2 py-2 md:px-3">
                            <Link
                                :href="show(drug.code)"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ drug.name }}
                            </Link>
                        </td>
                        <td class="px-2 py-2 md:px-3">{{ drug.inn }}</td>
                        <td class="hidden px-2 py-2 md:table-cell md:px-3">
                            {{ drug.dosage_form }}
                        </td>
                        <!-- Дозировка не разрывается, строка переносится только между дозировками. -->
                        <td class="px-2 py-2 md:px-3">
                            <template
                                v-for="(strength, i) in drug.strengths"
                                :key="strength"
                            >
                                <span class="whitespace-nowrap"
                                    >{{ strength
                                    }}{{
                                        i < drug.strengths.length - 1 ? ',' : ''
                                    }}</span
                                >{{ ' ' }}
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
