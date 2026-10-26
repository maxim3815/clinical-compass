<script setup lang="ts">
import { Frown } from '@lucide/vue';
import { computed } from 'vue';
import { MOOD_FACES, lowMoodText } from '@/lib/moods';
import type { DoctorMoods, Mood } from '@/types';

/**
 * Настроение в карточке пациента: лица, которые пациент по желанию выбирал, отмечая приём
 * препарата, по дням за две недели. Времена приёма — строками: одно время во всех днях на
 * одной высоте. Пустой день виден: на нём серия плохого настроения прерывается. Серия от трёх
 * дней — строкой сверху и подсветкой дней, это не алерт.
 */
const props = defineProps<{
    moods: DoctorMoods;
}>();

const MOODS: Mood[] = ['sad', 'neutral', 'happy'];

// Цвет — в помощь форме и подписи: грустное янтарное, весёлое основное.
const COLOR: Record<Mood, string> = {
    sad: 'text-warning',
    neutral: 'text-muted-foreground',
    happy: 'text-primary',
};

// При одном времени приёма подписывать каждое лицо незачем: время — в легенде.
const oneSlot = computed(() => props.moods.slots.length === 1);

function markAt(day: DoctorMoods['days'][number], slot: string) {
    return day.marks.find((mark) => mark.slot === slot) ?? null;
}
</script>

<template>
    <section
        id="moods"
        class="flex scroll-mt-20 flex-col gap-3"
        aria-labelledby="moods-title"
        data-test="moods"
    >
        <div>
            <h2 id="moods-title" class="text-lg font-semibold">
                Настроение по отметкам пациента
            </h2>
            <p class="text-sm text-muted-foreground">
                Пациент по желанию выбирает лицо, когда отмечает приём
                препарата. Прошлые отметки пациенту не видны. Последние 2
                недели.
            </p>
        </div>

        <p
            v-if="moods.streak"
            class="flex items-start gap-2 text-sm"
            data-test="mood-streak"
        >
            <Frown
                class="mt-0.5 size-4 shrink-0 text-warning"
                aria-hidden="true"
            />
            <span
                >{{ `${lowMoodText(moods.streak.days)}, `
                }}<span class="whitespace-nowrap">{{
                    `${moods.streak.from}–${moods.streak.to}`
                }}</span
                >{{
                    ': в эти дни пациент отмечал только плохое настроение.'
                }}</span
            >
        </p>

        <p
            v-if="!moods.any"
            class="rounded-xl border border-dashed bg-card p-4 text-sm text-muted-foreground"
        >
            {{
                moods.planned
                    ? 'Пациент ещё не отмечал настроение.'
                    : 'Лица появятся, когда у пациента будут плановые приёмы.'
            }}
        </p>

        <template v-else>
            <ol
                class="grid grid-cols-7 gap-1.5 rounded-xl border bg-card p-3 shadow-xs @3xl:grid-cols-14"
                role="list"
                aria-label="Настроение по дням"
                data-test="mood-days"
            >
                <li
                    v-for="day in moods.days"
                    :key="day.iso"
                    class="flex min-w-0 flex-col items-center gap-1 rounded-lg py-1.5 text-center"
                    :class="[
                        day.in_streak
                            ? 'bg-warning-subtle ring-1 ring-warning-border'
                            : day.today
                              ? 'bg-muted'
                              : '',
                        // Сегодня видно и внутри серии: рамка темнее.
                        day.today && day.in_streak
                            ? 'ring-2 ring-foreground/30'
                            : '',
                    ]"
                    :data-test="`mood-day-${day.iso}`"
                    :data-streak="day.in_streak || undefined"
                >
                    <span class="sr-only">{{
                        `${day.today ? 'Сегодня, ' : ''}${day.long}${day.in_streak ? ', в серии плохого настроения' : ''}:`
                    }}</span>
                    <span
                        class="text-xs text-muted-foreground"
                        aria-hidden="true"
                        >{{ day.weekday }}</span
                    >
                    <span
                        class="text-xs font-medium tabular-nums"
                        aria-hidden="true"
                        >{{ day.date }}</span
                    >
                    <!-- Пустой день: прошлый — прочерк, сегодняшний ещё идёт. -->
                    <span
                        v-if="day.marks.length === 0"
                        class="text-muted-foreground"
                        ><span aria-hidden="true">{{
                            day.today ? '' : '—'
                        }}</span
                        ><span class="sr-only">{{
                            day.today ? 'день ещё идёт' : 'лица не выбраны'
                        }}</span></span
                    >
                    <template v-else>
                        <span
                            v-for="slot in moods.slots"
                            :key="slot"
                            class="flex flex-col items-center"
                            :data-mood="markAt(day, slot)?.mood"
                        >
                            <template v-if="markAt(day, slot)">
                                <component
                                    :is="
                                        MOOD_FACES[markAt(day, slot)!.mood].icon
                                    "
                                    class="size-5"
                                    :class="COLOR[markAt(day, slot)!.mood]"
                                    aria-hidden="true"
                                />
                                <span
                                    v-if="!oneSlot"
                                    class="text-xs text-muted-foreground tabular-nums"
                                    aria-hidden="true"
                                    >{{ slot }}</span
                                >
                                <span class="sr-only">{{
                                    `${slot}: ${markAt(day, slot)!.label.toLowerCase()} настроение`
                                }}</span>
                            </template>
                            <!-- Это время не отмечено: место пустое, чтобы строки не съезжали. -->
                            <span
                                v-else
                                class="flex flex-col items-center"
                                aria-hidden="true"
                            >
                                <span class="size-5" />
                                <span v-if="!oneSlot" class="text-xs"
                                    >&nbsp;</span
                                >
                            </span>
                        </span>
                    </template>
                </li>
            </ol>

            <!-- Легенда: лицо узнаётся и формой, и словом, а не только цветом. -->
            <p
                class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground"
                data-test="mood-legend"
            >
                <span
                    v-for="mood in MOODS"
                    :key="mood"
                    class="inline-flex items-center gap-1.5"
                >
                    <component
                        :is="MOOD_FACES[mood].icon"
                        class="size-4"
                        :class="COLOR[mood]"
                        aria-hidden="true"
                    />
                    {{ MOOD_FACES[mood].label.toLowerCase() }}
                </span>
                <span v-if="oneSlot">{{
                    `все отметки — после приёма в ${moods.slots[0]}`
                }}</span>
            </p>
        </template>
    </section>
</template>
