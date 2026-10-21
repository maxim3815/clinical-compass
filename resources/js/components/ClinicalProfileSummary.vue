<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { ClinicalProfile } from '@/types';

defineProps<{
    profile: ClinicalProfile;
}>();
</script>

<template>
    <div class="flex flex-col gap-4 text-sm" data-test="clinical-profile">
        <p>
            <span class="text-muted-foreground">Пол:</span>
            {{ profile.sex_label ?? 'не указан' }}
        </p>

        <div class="flex flex-col gap-1.5">
            <h3 class="text-muted-foreground">Диагнозы</h3>
            <p v-if="profile.diagnoses.length === 0">
                Диагноз не указан: показания к назначению не проверяются.
            </p>
            <ul v-else class="flex flex-col gap-2" data-test="diagnoses">
                <li v-for="diagnosis in profile.diagnoses" :key="diagnosis.id">
                    <p>
                        <span class="font-semibold">{{ diagnosis.code }}</span>
                        {{ diagnosis.name }}
                    </p>
                    <p class="flex flex-wrap items-center gap-2">
                        <Badge v-if="diagnosis.is_main" variant="secondary"
                            >основной</Badge
                        >
                        <span class="text-muted-foreground"
                            >с {{ diagnosis.established_on }}</span
                        >
                    </p>
                </li>
            </ul>
        </div>

        <div class="flex flex-col gap-1.5">
            <h3 class="text-muted-foreground">Факты</h3>
            <p v-if="profile.facts.length === 0">Факты не внесены.</p>
            <dl v-else class="flex flex-col gap-2" data-test="facts">
                <div v-for="fact in profile.facts" :key="fact.id">
                    <dt>{{ fact.name }}</dt>
                    <dd>
                        <span class="font-semibold">{{ fact.value }}</span>
                        <span v-if="fact.comment"> ({{ fact.comment }})</span>
                        <span class="text-muted-foreground">
                            · {{ fact.observed_on }}</span
                        >
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</template>
