<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, useTemplateRef } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    drugSearchKey,
    isPartOfDrugName,
    normalizeDrugName,
    searchDrugs,
    similarDrugs,
} from '@/lib/drugSearch';
import { plural } from '@/lib/plural';
import type { DrugChoice, DrugOption, SearchableDrug } from '@/lib/drugSearch';

/**
 * Поле с подсказками по паттерну ARIA combobox: пациент пишет название с упаковки или действующее
 * вещество и выбирает препарат из вариантов. Если точных вариантов нет, показываются похожие
 * названия: при опечатке препарат иначе ушёл бы в «другие», и сочетания с ним не проверились бы.
 * Последний вариант — оставить название как написано: если ничего не нашлось или набранное длиннее
 * найденного названия («Корвалол Фито» — не «Корвалол»), но не пока название набирается («кор»).
 * Доза после названия («Кордарон 200 мг») поиску не мешает. Список идёт под полем, а не поверх: на телефоне его не
 * закрывает клавиатура, экранный диктор проходит его жестами.
 */
const props = defineProps<{
    id: string;
    drugs: SearchableDrug[];
    maxlength: number;
    error?: string;
    describedby?: string;
}>();

const choice = defineModel<DrugChoice | null>({ required: true });

type Entry = { kind: 'drug'; option: DrugOption } | { kind: 'other' };

const query = ref(
    choice.value === null
        ? ''
        : choice.value.kind === 'drug'
          ? choice.value.label
          : choice.value.name,
);
const open = ref(false);
const active = ref(-1);
// Вариант выделен стрелками: при уходе из поля Tab выбирает его.
const byKeyboard = ref(false);
const announcement = ref('');
const confirmation = useTemplateRef<HTMLElement>('confirmation');

const listId = `${props.id}-options`;
const similarId = `${props.id}-similar`;

const options = computed(() => searchDrugs(props.drugs, query.value));
const similar = computed(() =>
    options.value.length === 0 ? similarDrugs(props.drugs, query.value) : [],
);
const otherName = computed(() => query.value.trim());
const exact = computed(() =>
    options.value.find(
        (option) =>
            normalizeDrugName(option.label) === drugSearchKey(query.value),
    ),
);
const offersAsWritten = computed(
    () =>
        drugSearchKey(query.value).length >= 3 &&
        exact.value === undefined &&
        !isPartOfDrugName(props.drugs, query.value),
);
const entries = computed<Entry[]>(() => [
    ...(options.value.length > 0 ? options.value : similar.value).map(
        (option): Entry => ({ kind: 'drug', option }),
    ),
    ...(offersAsWritten.value ? [{ kind: 'other' } as Entry] : []),
]);
const fuzzy = computed(
    () => options.value.length === 0 && similar.value.length > 0,
);
const expanded = computed(() => open.value && entries.value.length > 0);

function optionId(index: number): string {
    return `${props.id}-option-${index}`;
}

// Диктор говорит не на каждую букву, а когда набор затих; одинаковый текст подряд тоже звучит.
let announceTimer: number | undefined;

function announce(text: string, delay = 100): void {
    window.clearTimeout(announceTimer);
    announcement.value = '';
    announceTimer = window.setTimeout(() => (announcement.value = text), delay);
}

onBeforeUnmount(() => window.clearTimeout(announceTimer));

function announceOptions(): void {
    const found = entries.value.filter((entry) => entry.kind === 'drug').length;

    if (fuzzy.value) {
        announce(`Точного совпадения нет. Похожих названий: ${found}.`, 450);
    } else if (found === 0) {
        announce(
            offersAsWritten.value
                ? 'В списке такого препарата нет. Можно оставить название как написано.'
                : '',
            450,
        );
    } else {
        announce(
            `${found} ${plural(found, ['вариант', 'варианта', 'вариантов'])}.`,
            450,
        );
    }
}

// Значение берётся из события, а не через v-model: на Android клавиатура набирает слово как
// composition, и v-model не обновил бы список, пока слово не дописано.
function onInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;

    // После выбора касанием во время composition браузер присылает input с прежним текстом:
    // выбор он не сбрасывает.
    if (value === query.value) {
        return;
    }

    query.value = value;
    choice.value = null;
    open.value = true;
    active.value = -1;
    byKeyboard.value = false;
    announceOptions();
}

function choose(entry: Entry, announceChoice = true): void {
    if (entry.kind === 'other') {
        choice.value = { kind: 'other', name: otherName.value };
        query.value = otherName.value;

        if (announceChoice) {
            announce(`Выбрано: ${otherName.value}, как написано.`);
        }
    } else {
        const option = entry.option;
        choice.value = {
            kind: 'drug',
            drugId: option.drugId,
            tradeName: option.tradeName,
            label: option.label,
            substance: option.substance,
        };
        query.value = option.label;

        if (announceChoice) {
            announce(
                option.substance
                    ? `Выбрано: ${option.label}, действующее вещество ${option.substance}.`
                    : `Выбрано: ${option.label}.`,
            );
        }
    }

    open.value = false;
    active.value = -1;
    byKeyboard.value = false;
}

// Касанием: список скрывается, и курсор диктора остался бы на исчезнувшем варианте. Он
// переходит на строку под полем, где сказано, что выбрано: она и звучит, без объявления.
async function chooseByPointer(entry: Entry): Promise<void> {
    choose(entry, false);
    await nextTick();
    confirmation.value?.focus();
}

function move(step: number): void {
    if (!expanded.value) {
        open.value = otherName.value !== '';
    }

    if (entries.value.length === 0) {
        return;
    }

    const count = entries.value.length;
    active.value =
        active.value < 0
            ? step > 0
                ? 0
                : count - 1
            : (active.value + step + count) % count;
    byKeyboard.value = true;
    nextTick(() =>
        document
            .getElementById(optionId(active.value))
            ?.scrollIntoView({ block: 'nearest' }),
    );
}

function onKeydown(event: KeyboardEvent): void {
    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            move(1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            move(-1);
            break;
        case 'Enter':
            // Пока список открыт, Enter выбирает вариант, а не отправляет форму: выделенный,
            // совпавший с набранным целиком или единственный.
            if (expanded.value) {
                event.preventDefault();
                const exactIndex = entries.value.findIndex(
                    (entry) =>
                        entry.kind === 'drug' && entry.option === exact.value,
                );
                const index =
                    active.value >= 0
                        ? active.value
                        : exactIndex >= 0
                          ? exactIndex
                          : entries.value.length === 1
                            ? 0
                            : -1;

                if (index >= 0) {
                    choose(entries.value[index]);
                } else {
                    announce(
                        'Выберите препарат стрелками вверх и вниз или касанием.',
                    );
                }
            }

            break;
        case 'Escape':
            if (expanded.value) {
                event.preventDefault();
                open.value = false;
                active.value = -1;
            }

            break;
    }
}

// Уход из поля: выбирается вариант, выделенный стрелками, или совпавший с набранным целиком.
function onBlur(): void {
    const keyboardEntry =
        byKeyboard.value && expanded.value
            ? entries.value[active.value]
            : undefined;

    open.value = false;
    active.value = -1;

    if (keyboardEntry) {
        choose(keyboardEntry);
    } else if (choice.value === null && exact.value) {
        choose({ kind: 'drug', option: exact.value });
    }
}

function onFocus(): void {
    open.value = choice.value === null && otherName.value !== '';
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <!-- Обычное поле с :value, а не Input с v-model: v-model не пишет в поле, пока клавиатура
             Android набирает слово (composition), и выбранный вариант не попал бы в поле. -->
        <input
            :id="id"
            :value="query"
            type="text"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="expanded"
            :aria-controls="listId"
            :aria-activedescendant="
                expanded && active >= 0 ? optionId(active) : undefined
            "
            :aria-invalid="error ? true : undefined"
            :aria-describedby="describedby"
            :maxlength="maxlength"
            autocomplete="off"
            autocorrect="off"
            spellcheck="false"
            placeholder="Например, Кардиомагнил"
            class="h-10 w-full min-w-0 rounded-lg border border-input bg-card px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none selection:bg-primary selection:text-primary-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid aria-invalid:border-destructive aria-invalid:ring-danger-subtle pointer-coarse:h-11"
            data-test="drug-search"
            @input="onInput"
            @keydown="onKeydown"
            @focus="onFocus"
            @blur="onBlur"
        />

        <!-- Ошибка сразу под полем: открытый список не уводит её ниже экрана. -->
        <InputError :id="`${id}-error`" :message="error" />

        <p
            v-if="expanded && fuzzy"
            :id="similarId"
            class="text-muted-foreground"
        >
            Точного совпадения нет. Может быть, вы имели в виду:
        </p>

        <ul
            v-show="expanded"
            :id="listId"
            role="listbox"
            :aria-label="fuzzy ? undefined : 'Препараты'"
            :aria-labelledby="fuzzy ? similarId : undefined"
            class="flex max-h-[60vh] flex-col overflow-y-auto rounded-lg border bg-card shadow-sm pointer-coarse:max-h-none pointer-coarse:overflow-visible"
            data-test="drug-options"
        >
            <li
                v-for="(entry, index) in entries"
                :id="optionId(index)"
                :key="entry.kind === 'drug' ? entry.option.key : 'other'"
                role="option"
                :aria-selected="active === index"
                class="flex min-h-11 shrink-0 cursor-pointer gap-2 px-3 py-2 hover:bg-secondary/60 aria-selected:bg-secondary aria-selected:shadow-[inset_4px_0_0_var(--primary)]"
                :class="
                    entry.kind === 'drug'
                        ? 'border-b last:border-b-0'
                        : 'border-t border-dashed first:border-t-0'
                "
                :data-test="
                    entry.kind === 'drug' ? 'drug-option' : 'drug-option-other'
                "
                @pointerdown.prevent
                @click="chooseByPointer(entry)"
            >
                <template v-if="entry.kind === 'drug'">
                    <span class="flex flex-col justify-center">
                        <span class="font-medium">{{
                            entry.option.label
                        }}</span>
                        <span
                            v-if="entry.option.substance"
                            class="text-muted-foreground"
                        >
                            {{ entry.option.substance }}
                        </span>
                    </span>
                </template>
                <template v-else>
                    <Plus
                        class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    />
                    <span class="flex flex-col justify-center">
                        <span class="font-medium"
                            >Оставить как написано: «{{ otherName }}»</span
                        >
                        <span class="text-muted-foreground">
                            Такого названия нет в списке
                        </span>
                    </span>
                </template>
            </li>
        </ul>

        <p
            v-if="choice?.kind === 'drug' && choice.substance"
            ref="confirmation"
            tabindex="-1"
            class="text-muted-foreground outline-none"
            data-test="drug-substance"
        >
            <span class="sr-only">Выбрано: {{ choice.label }}. </span
            >Действующее вещество: {{ choice.substance }}
        </p>
        <p
            v-else-if="choice?.kind === 'other'"
            ref="confirmation"
            tabindex="-1"
            class="text-muted-foreground outline-none"
            data-test="drug-other-notice"
        >
            <span class="sr-only">Выбрано: {{ choice.name }}. </span>Проверьте
            название по упаковке: врач увидит его так, как вы написали.
        </p>
        <p
            v-else-if="choice?.kind === 'drug'"
            ref="confirmation"
            tabindex="-1"
            class="sr-only"
        >
            Выбрано: {{ choice.label }}
        </p>

        <p class="sr-only" role="status">{{ announcement }}</p>
    </div>
</template>
