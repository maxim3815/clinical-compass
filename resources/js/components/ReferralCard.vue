<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MessageCircle, Stethoscope } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { show as chat } from '@/routes/patient/chat';
import type { PatientReferral } from '@/types';

/**
 * Направление к психиатру на «Сегодня»: к кому, насколько нужна консультация и что дальше.
 * Без основания и баллов: интерпретация у врача. Без красного: опасности здесь нет.
 */
defineProps<{
    referral: PatientReferral;
}>();
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-2xl border border-primary/40 bg-card p-4 shadow-xs"
        aria-labelledby="referral-card-title"
        data-test="referral-card"
    >
        <div class="flex items-start gap-3">
            <Stethoscope class="mt-0.5 size-6 shrink-0 text-primary" />
            <div class="flex min-w-0 flex-col gap-1">
                <h2 id="referral-card-title" class="text-xl font-semibold">
                    {{
                        referral.status === 'accepted'
                            ? 'Теперь вас ведёт психиатр'
                            : 'Направление к психиатру'
                    }}
                </h2>
                <template v-if="referral.status === 'accepted'">
                    <p class="break-words">
                        {{
                            `С ${referral.accepted_at} ${referral.to.name} — ваш врач в приложении. Написать можно в чате.`
                        }}
                    </p>
                </template>
                <template v-else>
                    <p class="break-words">
                        {{ `К кому: ${referral.to.name}` }}
                    </p>
                    <p data-test="referral-card-category">
                        {{ `${referral.category}.` }}
                    </p>
                    <p class="text-muted-foreground">
                        Психиатр посмотрит направление и ваши ответы. Когда
                        психиатр станет вашим врачом в приложении, здесь
                        появится чат с психиатром.
                    </p>
                </template>
            </div>
        </div>
        <Button
            v-if="referral.status === 'accepted'"
            variant="outline"
            size="lg"
            class="self-start rounded-full"
            as-child
        >
            <Link :href="chat()">
                <MessageCircle />
                Написать врачу
            </Link>
        </Button>
    </section>
</template>
