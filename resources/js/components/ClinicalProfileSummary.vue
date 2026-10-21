<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { ClinicalProfile } from '@/types';

defineProps<{
    profile: ClinicalProfile;
}>();
</script>

<template>
    <div class="flex flex-col gap-3 text-sm" data-test="clinical-profile">
        <p>
            <span class="text-muted-foreground">Пол:</span>
            {{ profile.sex_label ?? 'не указан' }}
        </p>

        <div class="flex flex-col gap-1">
            <p class="text-muted-foreground">Диагнозы</p>
            <p v-if="profile.diagnoses.length === 0">Диагноз не указан.</p>
            <ul v-else class="flex flex-col gap-1" data-test="diagnoses">
                <li
                    v-for="diagnosis in profile.diagnoses"
                    :key="diagnosis.id"
                    class="flex flex-wrap items-center gap-2"
                >
                    <span class="font-medium">{{ diagnosis.code }}</span>
                    <span>{{ diagnosis.name }}</span>
                    <Badge v-if="diagnosis.is_main" variant="secondary"
                        >основной</Badge
                    >
                    <span class="text-muted-foreground"
                        >с {{ diagnosis.established_on }}</span
                    >
                </li>
            </ul>
        </div>

        <div class="flex flex-col gap-1">
            <p class="text-muted-foreground">Факты</p>
            <p v-if="profile.facts.length === 0">Факты не внесены.</p>
            <dl
                v-else
                class="grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,16rem)_1fr]"
                data-test="facts"
            >
                <template v-for="fact in profile.facts" :key="fact.id">
                    <dt>{{ fact.name }}</dt>
                    <dd class="-mt-1 sm:mt-0">
                        <span class="font-medium">{{ fact.value }}</span>
                        <span v-if="fact.comment"> ({{ fact.comment }})</span>
                        <span class="text-muted-foreground">
                            · {{ fact.observed_on }},
                            {{ fact.recorded_by }}</span
                        >
                    </dd>
                </template>
            </dl>
        </div>
    </div>
</template>
