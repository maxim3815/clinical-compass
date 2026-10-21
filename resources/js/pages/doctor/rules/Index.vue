<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { show as drug } from '@/routes/doctor/drugs';
import { index } from '@/routes/doctor/rules';
import type { SafetyRuleView } from '@/types';

type RuleRow = SafetyRuleView & {
    condition: string | null;
    codes: string[] | null;
};

defineProps<{
    drugs: { code: string; name: string; rules: RuleRow[] }[];
    total: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Правила', href: index() }],
    },
});
</script>

<template>
    <Head title="Правила" />

    <div class="flex max-w-4xl flex-col gap-6 p-4">
        <Heading
            title="Правила"
            description="По каким правилам проверяется назначение каждого препарата и на какой раздел инструкции опирается правило. Красное — противопоказано, назначить можно только с обоснованием. Жёлтое — с осторожностью."
        />

        <p class="text-sm text-muted-foreground" data-test="rules-total">
            Действующих правил: {{ total }}.
        </p>

        <section
            v-for="item in drugs"
            :key="item.code"
            class="flex flex-col gap-3"
            :data-drug="item.code"
            data-test="rules-drug"
        >
            <h2 class="text-lg font-medium">
                <Link
                    :href="drug(item.code)"
                    class="underline-offset-4 hover:underline"
                    >{{ item.name }}</Link
                >
            </h2>

            <ul class="flex flex-col gap-2">
                <li
                    v-for="rule in item.rules"
                    :key="rule.rule"
                    class="flex flex-col gap-2 rounded-lg border p-3 text-sm"
                    :data-rule="rule.rule"
                    data-test="rule"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            :class="
                                rule.level === 'contraindicated'
                                    ? 'border-danger-border bg-danger-subtle text-danger'
                                    : 'border-warning-border bg-warning-subtle text-warning'
                            "
                            >{{ rule.level_label }}</Badge
                        >
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
                            class="inline-flex items-center gap-1 underline underline-offset-4"
                            >{{ rule.source.document
                            }}<ExternalLink class="size-3"
                        /></a>
                        {{ `· ${rule.source.section}` }}
                    </p>
                    <details
                        v-if="rule.source.quote || rule.condition"
                        class="text-muted-foreground"
                    >
                        <summary class="cursor-pointer">
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
                            <code class="rounded bg-muted px-1">{{
                                rule.condition
                            }}</code>
                        </p>
                    </details>
                </li>
            </ul>
        </section>
    </div>
</template>
