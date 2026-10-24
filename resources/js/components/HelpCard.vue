<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LifeBuoy, MessageCircle } from '@lucide/vue';
import HelpCallButtons from '@/components/HelpCallButtons.vue';
import { Button } from '@/components/ui/button';
import { show as chat } from '@/routes/patient/chat';

/**
 * Куда обратиться, если тяжело: 112, кризисная линия и чат с врачом. На «Сегодня» — пока врач
 * не закрыл алерт, после отправки опросника — когда ответы подпали под правило алертинга.
 * Самого алерта пациент не видит [медик].
 */
defineProps<{
    // На «Сегодня» короче: карточка не должна сдвигать приёмы с первого экрана.
    compact?: boolean;
}>();
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-2xl border border-primary/30 bg-secondary p-4 text-secondary-foreground"
        aria-labelledby="help-card-title"
        data-test="help-card"
    >
        <div class="flex items-start gap-3">
            <LifeBuoy class="mt-0.5 size-6 shrink-0" />
            <div class="flex flex-col gap-1">
                <h2 id="help-card-title" class="text-xl font-semibold">
                    Если вам сейчас тяжело
                </h2>
                <p v-if="compact">
                    Позвоните 112 или на кризисную линию. Звонок бесплатный.
                </p>
                <p v-else>
                    Позвоните 112 или на кризисную линию. Звонок бесплатный.
                    Можно написать врачу, но он не всегда отвечает сразу.
                </p>
            </div>
        </div>
        <HelpCallButtons />
        <Button
            :variant="compact ? 'ghost' : 'outline'"
            size="lg"
            :class="
                compact ? 'self-start text-primary' : 'rounded-full bg-card'
            "
            as-child
        >
            <Link :href="chat()" data-test="help-write-doctor">
                <MessageCircle />
                Написать врачу
            </Link>
        </Button>
    </section>
</template>
