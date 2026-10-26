<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { Errors, Page } from '@inertiajs/core';
import { ref } from 'vue';
import MoodController from '@/actions/App/Http/Controllers/Patient/MoodController';
import { MOOD_FACES } from '@/lib/moods';
import type { DoseGroup, Mood } from '@/types';

/**
 * Настроение после отметки приёма: три лица с подписями внизу карточки времени, по желанию.
 * Выбор сохраняется сразу, меняется до конца дня, повторное касание его снимает; ничего не
 * блокирует и окон не открывает. Врачу — лица в карточке и метка в списке после нескольких
 * плохих дней подряд.
 */
const props = defineProps<{
    // Время приёма группы: «8:30».
    slot: string;
    mood: Mood | null;
    // Заголовок карточки: группа лиц называется по нему («Сегодня в 8:30»).
    timeId: string;
}>();

const MOODS: Mood[] = ['sad', 'neutral', 'happy'];

const promptId = `mood-${props.timeId}`;
const hintId = `mood-hint-${props.timeId}`;
// Что сохранилось — для скринридера; ошибка — видимой строкой.
const status = ref('');
const error = ref<string | null>(null);
// Двойное касание не снимает только что выбранное лицо: пожилые часто касаются дважды.
const DOUBLE_TAP_MS = 800;
let chosenAt = 0;

type TodayProps = { today: { date: string; groups: DoseGroup[] } };

// Лицо выделяется сразу: при ошибке, обрыве связи или новом нажатии Inertia вернёт или
// пересчитает пропсы сама.
function withMood(
    page: Page['props'],
    mood: Mood | null,
): Partial<Page['props']> {
    const today = (page as unknown as TodayProps).today;

    return {
        today: {
            ...today,
            groups: today.groups.map((group) =>
                group.time === props.slot ? { ...group, mood } : group,
            ),
        },
    };
}

function choose(mood: Mood): void {
    // Повторное касание выбранного лица снимает выбор: нажатое по ошибке не уходит врачу.
    if (props.mood === mood && Date.now() - chosenAt < DOUBLE_TAP_MS) {
        return;
    }

    const next = props.mood === mood ? null : mood;
    chosenAt = next === null ? 0 : Date.now();
    const options = {
        preserveScroll: true,
        only: ['today'],
        // Уход на другую вкладку сразу после касания не отменяет сохранение.
        async: true,
        optimistic: (page: Page['props']) => withMood(page, next),
        onStart: () => {
            error.value = null;
            status.value = '';
        },
        onSuccess: () => {
            status.value =
                next === null
                    ? 'Выбор снят.'
                    : `Сохранено: ${MOOD_FACES[next].label.toLowerCase()} настроение.`;
        },
        onError: (errors: Errors) => {
            error.value =
                errors.slot ??
                errors.mood ??
                'Не сохранилось. Попробуйте ещё раз.';
        },
    };

    if (next === null) {
        router.delete(MoodController.destroy.url(), {
            ...options,
            data: { slot: props.slot },
        });
    } else {
        router.post(
            MoodController.store.url(),
            { slot: props.slot, mood: next },
            options,
        );
    }
}
</script>

<template>
    <div
        class="flex flex-col gap-3 border-t px-4 py-3"
        role="group"
        :aria-labelledby="`${promptId} ${timeId}`"
        :aria-describedby="props.mood === null ? hintId : undefined"
        data-test="mood-picker"
    >
        <div>
            <p :id="promptId">Как настроение?</p>
            <!-- Пояснение — пока лицо не выбрано: после выбора карточка снова короткая. -->
            <p
                v-if="props.mood === null"
                :id="hintId"
                class="text-muted-foreground"
            >
                Ответ увидит ваш врач. Отвечать не обязательно.
            </p>
        </div>
        <div class="grid max-w-80 grid-cols-3 gap-3">
            <!-- Кнопка — круг 48 px и подпись: на маленьком экране лица различаются и словом. -->
            <button
                v-for="mood in MOODS"
                :key="mood"
                type="button"
                class="group flex min-w-0 flex-col items-center gap-1 rounded-xl py-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                :aria-pressed="props.mood === mood"
                :data-test="`mood-${mood}`"
                @click="choose(mood)"
            >
                <span
                    class="flex size-12 items-center justify-center rounded-full border transition-colors"
                    :class="
                        props.mood === mood
                            ? 'border-primary bg-primary text-primary-foreground forced-colors:outline-2 forced-colors:outline-offset-2 forced-colors:outline-solid'
                            : 'border-input bg-card text-muted-foreground group-hover:border-primary/60 group-hover:text-foreground'
                    "
                >
                    <component
                        :is="MOOD_FACES[mood].icon"
                        class="size-7"
                        aria-hidden="true"
                    />
                </span>
                <span
                    :class="
                        props.mood === mood
                            ? 'font-medium text-foreground'
                            : 'text-muted-foreground'
                    "
                    >{{ MOOD_FACES[mood].label }}</span
                >
            </button>
        </div>
        <p v-if="error" class="text-danger" role="alert" data-test="mood-error">
            {{ error }}
        </p>
        <p class="sr-only" role="status">{{ status }}</p>
    </div>
</template>
