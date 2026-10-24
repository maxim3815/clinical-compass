<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AssessmentController from '@/actions/App/Http/Controllers/Patient/AssessmentController';
import QuestionnaireForm from '@/components/QuestionnaireForm.vue';
import { home } from '@/routes/patient';
import type { QuestionnaireScale } from '@/types';

defineProps<{
    scales: QuestionnaireScale[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Сегодня',
                href: home(),
            },
            {
                title: 'Вопросы о самочувствии',
                href: AssessmentController.create(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Вопросы о самочувствии" />

    <QuestionnaireForm
        class="p-4"
        :scales="scales"
        :action="AssessmentController.store.url()"
        submit-label="Отправить врачу"
        remember-key="questionnaire"
    />
</template>
