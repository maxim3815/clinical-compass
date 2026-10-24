<script setup lang="ts">
import { CircleAlert, ExternalLink, Info } from '@lucide/vue';
import type { ReferralEvidence, ReferralMatch } from '@/types';

/**
 * Основание подсказки направления: сработавшие правила, на что они смотрели и источник
 * (клиническая рекомендация, раздел, цитата).
 */
defineProps<{
    matches: ReferralMatch[];
}>();

function evidence(item: ReferralEvidence): string {
    switch (item.type) {
        case 'answer':
            return `${item.name}, вопрос ${item.item}: ${item.label.toLowerCase()} (${item.score})`;
        case 'total':
            return `${item.name}: ${item.total} из ${item.max}, ${item.band}`;
        case 'fact':
            return `${item.name}: ${item.value}`;
    }
}
</script>

<template>
    <ul class="flex flex-col gap-3" data-test="referral-basis">
        <li
            v-for="match in matches"
            :key="match.code"
            class="flex gap-2"
            :data-test="`referral-rule-${match.code}`"
        >
            <CircleAlert
                v-if="match.category === 'required'"
                class="mt-0.5 size-4 shrink-0 text-danger"
                aria-hidden="true"
            />
            <Info
                v-else
                class="mt-0.5 size-4 shrink-0 text-warning"
                aria-hidden="true"
            />
            <div class="flex min-w-0 flex-col gap-1">
                <!-- Категория правила словом, а не только иконкой и цветом. -->
                <p>
                    <strong
                        v-if="match.category === 'required'"
                        class="font-bold text-danger"
                        >Обязательно:
                    </strong>
                    <span v-else class="font-medium text-warning"
                        >Желательно:
                    </span>
                    <span>{{ match.text }}</span>
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ match.evidence.map(evidence).join('; ') }}
                </p>
                <details class="text-sm text-muted-foreground">
                    <summary
                        class="w-fit cursor-pointer rounded-sm hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid pointer-coarse:flex pointer-coarse:min-h-11 pointer-coarse:items-center"
                    >
                        Источник<span class="sr-only">{{
                            `: ${match.text}`
                        }}</span>
                    </summary>
                    <p class="mt-1">
                        {{
                            `${match.source.document}, ${match.source.section}.`
                        }}
                    </p>
                    <blockquote
                        v-if="match.source.quote"
                        class="mt-1 border-l-2 pl-3 italic"
                    >
                        {{ match.source.quote }}
                    </blockquote>
                    <a
                        :href="match.source.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-1 inline-flex items-center gap-1 text-primary underline underline-offset-4 pointer-coarse:min-h-11"
                    >
                        Открыть документ<span class="sr-only">{{
                            ` ${match.source.document}`
                        }}</span>
                        <ExternalLink class="size-4" />
                    </a>
                </details>
            </div>
        </li>
    </ul>
</template>
