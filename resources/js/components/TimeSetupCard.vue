<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Clock } from '@lucide/vue';
import IntakeTimeController from '@/actions/App/Http/Controllers/Patient/IntakeTimeController';
import { Button } from '@/components/ui/button';

// Назначение, у которого пациент ещё не выбрал время приёма: без него нет ни напоминаний,
// ни отметок на «Сегодня».
defineProps<{
    prescription: { id: number; title: string };
}>();
</script>

<template>
    <section
        class="flex items-start gap-4 rounded-2xl border border-primary/50 bg-card p-4 shadow-xs"
        data-test="time-setup"
    >
        <div
            class="flex size-11 shrink-0 items-center justify-center rounded-full bg-secondary text-secondary-foreground"
        >
            <Clock class="size-6" />
        </div>
        <div class="flex min-w-0 flex-1 flex-col items-start gap-3">
            <div class="flex flex-col gap-1">
                <h2 class="text-lg font-semibold">Настройте время приёма</h2>
                <p class="break-words">
                    {{ prescription.title }}: выберите, во сколько вы будете
                    принимать препарат.
                </p>
            </div>
            <Button size="lg" variant="secondary" as-child>
                <Link
                    :href="
                        IntakeTimeController.edit(prescription.id, {
                            query: { from: 'today' },
                        })
                    "
                >
                    Выбрать время
                </Link>
            </Button>
        </div>
    </section>
</template>
