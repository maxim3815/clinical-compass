<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { plural } from '@/lib/plural';
import { index, show } from '@/routes/doctor/drugs';

type Drug = {
    code: string;
    name: string;
    inn: string;
    atc: string;
    dosage_form: string;
    strengths: { label: string; divisible: boolean }[];
    regimen: string;
    patient_instruction: string;
    missed_dose: string;
    window_hours: number | null;
    is_active: boolean;
    source: {
        trade_name: string;
        document: string;
        registration_number: string;
        url: string;
        edition: string;
    };
};

const props = defineProps<{
    drug: Drug;
}>();

defineOptions({
    layout: (props: { drug: Drug }) => ({
        breadcrumbs: [
            {
                title: 'Препараты',
                href: index(),
            },
            {
                title: props.drug.name,
                href: show(props.drug.code),
            },
        ],
    }),
});

const details = computed(() => [
    { label: 'МНН', value: props.drug.inn },
    { label: 'АТХ', value: props.drug.atc },
    { label: 'Лекарственная форма', value: props.drug.dosage_form },
    {
        label: 'Дозировки',
        value:
            props.drug.strengths
                .map(
                    (strength) =>
                        `${strength.label}, ${strength.divisible ? 'можно делить' : 'делить нельзя'}`,
                )
                .join('; ') || '—',
    },
    {
        label: 'Окно приёма',
        value:
            props.drug.window_hours === null
                ? 'по умолчанию: половина интервала до следующего приёма, но не больше 12 часов'
                : `${props.drug.window_hours} ${plural(props.drug.window_hours, ['час', 'часа', 'часов'])} после планового времени`,
    },
]);

const texts = computed(() => [
    { title: 'Режим по инструкции', text: props.drug.regimen },
    {
        title: 'Инструкция пациенту по умолчанию',
        text: props.drug.patient_instruction,
    },
    { title: 'Пациенту при пропуске дозы', text: props.drug.missed_dose },
]);
</script>

<template>
    <Head :title="drug.name" />

    <div class="flex max-w-3xl flex-col gap-8 p-4">
        <Heading :title="drug.name" description="Карточка препарата" />

        <p
            v-if="!drug.is_active"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
            data-test="drug-inactive"
        >
            Препарат убран из справочника: назначить его заново нельзя,
            действующие назначения продолжают работать.
        </p>

        <dl
            class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[max-content_1fr]"
            data-test="drug-details"
        >
            <template v-for="item in details" :key="item.label">
                <dt class="text-muted-foreground">{{ item.label }}</dt>
                <dd class="-mt-2 font-medium sm:mt-0">{{ item.value }}</dd>
            </template>
        </dl>

        <section
            v-for="item in texts"
            :key="item.title"
            class="flex flex-col gap-2"
        >
            <Heading variant="small" :title="item.title" />
            <p class="text-sm">{{ item.text }}</p>
        </section>

        <section class="flex flex-col gap-2" data-test="drug-source">
            <Heading variant="small" title="Источник" />
            <p class="text-sm">
                {{ drug.source.document }}, препарат «{{
                    drug.source.trade_name
                }}», РУ {{ drug.source.registration_number }},
                {{ drug.source.edition }}.
            </p>
            <a
                :href="drug.source.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 self-start text-sm underline underline-offset-4"
            >
                Открыть документ
                <ExternalLink class="size-4" />
            </a>
        </section>
    </div>
</template>
