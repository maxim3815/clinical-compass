<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { ExternalLink, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { plural } from '@/lib/plural';
import { index, show } from '@/routes/doctor/drugs';
import { index as rules } from '@/routes/doctor/rules';

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

    <div class="@container flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <header
            class="flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-xs"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ drug.name }}
                    </h1>
                    <p class="text-muted-foreground">
                        {{ drug.inn }} · АТХ {{ drug.atc }} ·
                        {{ drug.dosage_form }}
                    </p>
                </div>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="`${rules.url()}#drug-${drug.code}`">
                        <ShieldCheck />
                        Правила проверки
                    </Link>
                </Button>
            </div>

            <p
                v-if="!drug.is_active"
                class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
                data-test="drug-inactive"
            >
                Препарат убран из справочника: назначить его заново нельзя,
                действующие назначения продолжают работать.
            </p>

            <dl
                class="grid gap-x-6 gap-y-2 text-sm @xl:grid-cols-[max-content_1fr]"
                data-test="drug-details"
            >
                <template v-for="item in details" :key="item.label">
                    <dt class="text-muted-foreground">{{ item.label }}</dt>
                    <dd class="-mt-1 font-medium @xl:mt-0">{{ item.value }}</dd>
                </template>
            </dl>
        </header>

        <section
            v-for="item in texts"
            :key="item.title"
            class="flex flex-col gap-2 rounded-xl border bg-card p-4 shadow-xs"
        >
            <h2 class="font-semibold">{{ item.title }}</h2>
            <p class="text-sm">{{ item.text }}</p>
        </section>

        <section
            class="flex flex-col gap-2 rounded-xl border bg-card p-4 shadow-xs"
            data-test="drug-source"
        >
            <h2 class="font-semibold">Источник</h2>
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
                class="inline-flex items-center gap-1 self-start text-sm text-primary underline underline-offset-4 pointer-coarse:min-h-11"
            >
                Открыть документ
                <ExternalLink class="size-4" />
                <span class="sr-only">(в новой вкладке)</span>
            </a>
        </section>
    </div>
</template>
