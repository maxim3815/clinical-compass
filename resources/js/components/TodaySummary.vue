<script setup lang="ts">
import { CircleCheck, Clock } from '@lucide/vue';
import { computed } from 'vue';
import type { DoseGroup } from '@/types';

const props = defineProps<{
    // Приёмы на сегодня по времени, как на экране «Сегодня».
    groups: DoseGroup[];
}>();

const doses = computed(() => props.groups.flatMap((group) => group.doses));

const marked = computed(
    () =>
        doses.value.filter(
            (dose) => dose.status === 'taken' || dose.status === 'skipped',
        ).length,
);

// Ближайшая группа, которую отмечать ещё рано. Если что-то пора принять, это видно по карточке.
const next = computed(() => {
    if (doses.value.some((dose) => dose.due && dose.status === 'scheduled')) {
        return null;
    }

    return (
        props.groups.find((group) =>
            group.doses.some(
                (dose) => !dose.due && dose.status === 'scheduled',
            ),
        ) ?? null
    );
});
</script>

<template>
    <!-- Итог дня под заголовком «Сегодня». Сюда же встанет серия дней с приёмом по плану. -->
    <div class="flex flex-col gap-2" data-test="today-summary">
        <div class="flex items-center gap-3">
            <p class="shrink-0 font-medium" data-test="today-progress">
                Отмечено {{ marked }} из {{ doses.length }}
            </p>
            <div
                class="h-2 flex-1 overflow-hidden rounded-full bg-border"
                role="progressbar"
                aria-label="Отмечено приёмов за сегодня"
                :aria-valuenow="marked"
                aria-valuemin="0"
                :aria-valuemax="doses.length"
            >
                <div
                    class="h-full rounded-full bg-primary transition-[width]"
                    :style="{ width: `${(marked / doses.length) * 100}%` }"
                />
            </div>
        </div>
        <p
            v-if="marked === doses.length"
            class="flex items-center gap-2 font-medium text-success"
        >
            <CircleCheck class="size-5 shrink-0" />
            Все приёмы на сегодня отмечены
        </p>
        <p
            v-else-if="next"
            class="flex items-center gap-2 text-muted-foreground"
        >
            <Clock class="size-5 shrink-0" />
            Следующий приём в {{ next.time }}
        </p>
    </div>
</template>
