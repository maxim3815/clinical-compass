<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BellRing, MessageCircle, Phone } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { show as chat } from '@/routes/patient/chat';

/**
 * Карточка на «Сегодня», пока врач не закрыл алерт по срочному побочному эффекту: о чём пациент
 * сообщил, 112 и чат. Карточки с кризисной линией здесь нет: она про психологический кризис.
 * Статуса алерта пациент не видит [медик].
 */
defineProps<{
    symptoms: string[];
    // Когда последний раз сообщил: «сегодня в 16:24».
    at: string;
    // Недавно сообщил о «нужна помощь сейчас»: звонок в 112 — главная кнопка.
    emergency: boolean;
}>();
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-2xl border border-primary/30 bg-secondary p-4 text-secondary-foreground"
        aria-labelledby="side-effect-alert-title"
        data-test="side-effect-alert-card"
    >
        <div class="flex items-start gap-3">
            <BellRing class="mt-0.5 size-6 shrink-0" />
            <div class="flex min-w-0 flex-col gap-1">
                <h2 id="side-effect-alert-title" class="text-xl font-semibold">
                    {{ `Вы срочно сообщили врачу ${at}` }}
                </h2>
                <p class="break-words">
                    {{ symptoms.join(', ') }}
                </p>
                <p>
                    {{
                        emergency
                            ? 'Если вы ещё не позвонили в 112, позвоните сейчас.'
                            : 'Ответ может прийти не сразу. Если станет хуже, звоните 112.'
                    }}
                </p>
            </div>
        </div>
        <Button
            :variant="emergency ? 'default' : 'outline'"
            :size="emergency ? 'xl' : 'lg'"
            :class="emergency ? '' : 'rounded-full bg-card'"
            as-child
        >
            <a href="tel:112" data-test="side-effect-alert-call">
                <Phone />
                Позвонить 112
            </a>
        </Button>
        <Button
            variant="outline"
            size="lg"
            class="rounded-full bg-card"
            as-child
        >
            <Link :href="chat()">
                <MessageCircle />
                Написать врачу
            </Link>
        </Button>
    </section>
</template>
