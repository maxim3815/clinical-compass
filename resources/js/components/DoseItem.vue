<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CircleCheck, CircleDashed, CircleMinus, Info } from '@lucide/vue';
import { computed, nextTick, useTemplateRef } from 'vue';
import DoseController from '@/actions/App/Http/Controllers/Patient/DoseController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useDoseWords } from '@/composables/useDoseWords';
import type { Dose } from '@/types';

const props = withDefaults(
    defineProps<{
        dose: Dose;
        // Кнопки приёма, который пора принять: крупная главная, если в группе он один,
        // обычные или никаких, пока группу отмечают целиком кнопкой «Принял всё».
        actions?: 'primary' | 'secondary' | 'none';
        // Время группы: кнопки озвучиваются вместе с препаратом и временем.
        timeId?: string;
    }>(),
    { actions: 'secondary', timeId: undefined },
);

const emit = defineEmits<{
    // Открыть форму отметки: «Принял в другое время», «Не принял» или «Изменить».
    mark: [status: 'taken' | 'skipped'];
}>();

const words = useDoseWords();

const isMarked = computed(
    () => props.dose.status === 'taken' || props.dose.status === 'skipped',
);

// Одинаковые кнопки у разных приёмов скринридер различает по препарату и времени.
const nameId = computed(() => `dose-${props.dose.id}-name`);
const describedBy = computed(() =>
    [nameId.value, props.timeId].filter(Boolean).join(' '),
);

const status = useTemplateRef<HTMLElement>('status');
const takeForm = useForm({ status: 'taken' });

// Кнопка после отметки пропадает: фокус переходит к новому статусу приёма.
function take(): void {
    takeForm.submit(DoseController.update(props.dose.id), {
        preserveScroll: true,
        onSuccess: async () => {
            await nextTick();
            status.value?.focus();
        },
    });
}
</script>

<template>
    <div
        class="flex flex-col gap-3 break-words"
        data-test="dose"
        :data-dose-id="dose.id"
    >
        <div>
            <p :id="nameId" class="text-lg font-semibold">{{ dose.drug }}</p>
            <p>{{ dose.dose }}</p>
            <p
                v-if="dose.instruction && !isMarked"
                class="text-muted-foreground"
            >
                {{ dose.instruction }}
            </p>
        </div>

        <div
            v-if="isMarked"
            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2"
        >
            <div class="flex min-w-0 flex-col gap-1">
                <p
                    ref="status"
                    class="flex items-center gap-2 rounded-sm font-medium outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                    tabindex="-1"
                    data-test="dose-status"
                >
                    <CircleCheck
                        v-if="dose.status === 'taken'"
                        class="size-5 shrink-0 text-success"
                    />
                    <CircleMinus
                        v-else
                        class="size-5 shrink-0 text-muted-foreground"
                    />
                    {{ dose.status_text }}
                </p>
                <p v-if="dose.skip_comment" class="text-muted-foreground">
                    {{ dose.skip_comment }}
                </p>
                <p v-if="dose.marked_late" class="text-muted-foreground">
                    Отмечено позже, {{ dose.marked_late }}
                </p>
            </div>
            <Button
                size="lg"
                variant="ghost"
                class="px-3 text-primary"
                :aria-describedby="describedBy"
                data-test="edit-dose-mark"
                @click="
                    emit(
                        'mark',
                        dose.status === 'skipped' ? 'skipped' : 'taken',
                    )
                "
            >
                Изменить
            </Button>
        </div>

        <!--
            Окно приёма закрылось без отметки. «Принял» здесь спрашивает время: отметка «сейчас»
            записала бы вчерашний приём сегодняшним утром, и врач увидел бы поздний приём.
        -->
        <template v-else-if="dose.status === 'missed'">
            <p
                ref="status"
                class="flex items-center gap-2 rounded-sm font-medium text-muted-foreground outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                tabindex="-1"
                data-test="dose-status"
            >
                <CircleDashed class="size-5 shrink-0" />
                Нет отметки
            </p>
            <div
                v-if="dose.missed_dose"
                class="flex gap-3 rounded-xl bg-muted p-3"
                data-test="missed-dose"
            >
                <Info class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                <div>
                    <p class="font-medium">Если вы не приняли препарат</p>
                    <p>{{ dose.missed_dose }}</p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <Button
                    size="lg"
                    variant="outline"
                    :aria-describedby="describedBy"
                    data-test="take-dose-at"
                    @click="emit('mark', 'taken')"
                >
                    {{ words.took }}
                </Button>
                <Button
                    size="lg"
                    variant="outline"
                    :aria-describedby="describedBy"
                    data-test="skip-dose"
                    @click="emit('mark', 'skipped')"
                >
                    {{ words.didNotTake }}
                </Button>
            </div>
        </template>

        <!-- Подсказку «Отметить приём можно с …» показывает карточка группы. -->
        <div
            v-else-if="dose.due && actions !== 'none'"
            class="flex flex-col gap-1"
        >
            <Button
                :size="actions === 'primary' ? 'xl' : 'lg'"
                :variant="actions === 'primary' ? 'default' : 'outline'"
                class="w-full"
                :disabled="takeForm.processing"
                :aria-busy="takeForm.processing"
                :aria-describedby="describedBy"
                data-test="take-dose"
                @click="take"
            >
                <Spinner v-if="takeForm.processing" class="size-5" />
                <CircleCheck v-else />
                {{ words.took }}
            </Button>
            <div class="flex flex-wrap justify-center gap-x-3 gap-y-2">
                <Button
                    size="lg"
                    variant="ghost"
                    class="px-3 text-primary"
                    :aria-describedby="describedBy"
                    data-test="take-dose-at"
                    @click="emit('mark', 'taken')"
                >
                    {{ words.tookAtAnotherTime }}
                </Button>
                <Button
                    size="lg"
                    variant="ghost"
                    class="px-3 text-primary"
                    :aria-describedby="describedBy"
                    data-test="skip-dose"
                    @click="emit('mark', 'skipped')"
                >
                    {{ words.didNotTake }}
                </Button>
            </div>
        </div>
    </div>
</template>
