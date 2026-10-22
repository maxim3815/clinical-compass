<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink, OctagonAlert, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { plural } from '@/lib/plural';
import { show as drug } from '@/routes/doctor/drugs';
import { index } from '@/routes/doctor/rules';
import type { SafetyRuleView } from '@/types';

type RuleRow = SafetyRuleView & {
    condition: string | null;
    codes: string[] | null;
};

const props = defineProps<{
    drugs: { code: string; name: string; rules: RuleRow[] }[];
    total: number;
}>();

// Фильтр по уровню: врач ищет, что запрещено, или что требует осторожности.
const level = ref<'all' | 'contraindicated' | 'caution'>('all');
const levels = [
    { value: 'all', label: 'Все' },
    { value: 'contraindicated', label: 'Противопоказано' },
    { value: 'caution', label: 'С осторожностью' },
] as const;

const shown = computed(() =>
    props.drugs.map((drug) => ({
        ...drug,
        rules: drug.rules.filter(
            (rule) => level.value === 'all' || rule.level === level.value,
        ),
    })),
);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Правила', href: index() }],
    },
});
</script>

<template>
    <Head title="Правила" />

    <div class="@container flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Правила</h1>
            <p class="text-muted-foreground">
                По каким правилам проверяется назначение каждого препарата и на
                какой раздел инструкции опирается правило. «Противопоказано» —
                назначить можно только с обоснованием, «С осторожностью» —
                обоснование не нужно.
            </p>
            <p class="text-sm text-muted-foreground" data-test="rules-total">
                Действующих правил: {{ total }}.
            </p>
        </header>

        <!-- Переход к препарату и фильтр по уровню. -->
        <div
            class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
        >
            <nav aria-label="Препараты" class="flex flex-wrap gap-2 text-sm">
                <a
                    v-for="item in drugs"
                    :key="item.code"
                    :href="`#drug-${item.code}`"
                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 font-medium hover:bg-accent pointer-coarse:min-h-11"
                    >{{ item.name }}
                    <span class="text-muted-foreground">{{
                        item.rules.length
                    }}</span></a
                >
            </nav>
            <fieldset class="flex flex-wrap items-center gap-2 text-sm">
                <legend class="sr-only">Уровень</legend>
                <label
                    v-for="option in levels"
                    :key="option.value"
                    class="flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-input bg-card px-3 transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid pointer-coarse:min-h-11"
                >
                    <input
                        v-model="level"
                        type="radio"
                        name="level"
                        :value="option.value"
                        class="size-4 shrink-0 outline-none"
                    />
                    {{ option.label }}
                </label>
            </fieldset>
        </div>

        <section
            v-for="item in shown"
            :id="`drug-${item.code}`"
            :key="item.code"
            class="flex scroll-mt-20 flex-col gap-3"
            :data-drug="item.code"
            data-test="rules-drug"
        >
            <h2 class="text-lg font-semibold">
                <Link
                    :href="drug(item.code)"
                    class="underline-offset-4 hover:underline"
                    >{{ item.name }}</Link
                >
                <span class="font-normal text-muted-foreground">
                    ·
                    {{
                        `${item.rules.length} ${plural(item.rules.length, ['правило', 'правила', 'правил'])}`
                    }}</span
                >
            </h2>

            <p
                v-if="item.rules.length === 0"
                class="rounded-xl border border-dashed bg-card p-4 text-sm text-muted-foreground"
            >
                Правил этого уровня нет.
            </p>

            <ul
                v-else
                class="flex flex-col divide-y rounded-xl border bg-card shadow-xs"
            >
                <li
                    v-for="rule in item.rules"
                    :key="rule.rule"
                    class="flex flex-col gap-1.5 p-4 text-sm"
                    :data-rule="rule.rule"
                    :data-level="rule.level"
                    data-test="rule"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            :variant="
                                rule.level === 'contraindicated'
                                    ? 'danger'
                                    : 'warning'
                            "
                        >
                            <OctagonAlert
                                v-if="rule.level === 'contraindicated'"
                            />
                            <TriangleAlert v-else />
                            {{ rule.level_label }}
                        </Badge>
                        <Badge variant="outline">{{ rule.type_label }}</Badge>
                    </div>
                    <p>{{ rule.text }}</p>
                    <p v-if="rule.codes" class="text-muted-foreground">
                        {{ `Показания по МКБ-10: ${rule.codes.join(', ')}` }}
                    </p>
                    <p v-if="rule.advice" class="text-muted-foreground">
                        {{ rule.advice }}
                    </p>
                    <p class="text-muted-foreground">
                        Источник:
                        <a
                            :href="rule.source.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 underline underline-offset-4 hover:text-foreground"
                            >{{ rule.source.document
                            }}<ExternalLink class="size-3.5" /><span
                                class="sr-only"
                                >(в новой вкладке)</span
                            ></a
                        >
                        {{ `· ${rule.source.section}` }}
                    </p>
                    <details
                        v-if="rule.source.quote || rule.condition"
                        class="text-muted-foreground"
                    >
                        <summary
                            class="w-fit cursor-pointer rounded-sm hover:text-foreground pointer-coarse:py-2.5"
                        >
                            Цитата и условие
                        </summary>
                        <blockquote
                            v-if="rule.source.quote"
                            class="mt-2 border-l-2 pl-3 italic"
                        >
                            {{ rule.source.quote }}
                        </blockquote>
                        <p v-if="rule.condition" class="mt-2">
                            Условие:
                            <code class="rounded bg-muted px-1 break-all">{{
                                rule.condition
                            }}</code>
                        </p>
                    </details>
                </li>
            </ul>
        </section>
    </div>
</template>
