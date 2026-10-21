<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import type { ClinicalFact } from '@/types';

export type ObservationTypeOption = {
    id: number;
    code: string;
    name: string;
    value_type: 'boolean' | 'number' | 'choice';
    unit: string | null;
    min: number | null;
    max: number | null;
    options: { value: string; label: string }[] | null;
    female_only: boolean;
    // Все записи, сначала последняя: она и есть текущее значение.
    history: ClinicalFact[];
};

export type ObservationDraft = {
    // Пусто, пока врач не выбрал значение: «1»/«0», число или вариант из списка.
    value: string;
    comment: string;
    observed_on: string;
};

const props = defineProps<{
    type: ObservationTypeOption;
    today: string;
    commentLimit: number;
    // Ошибки этого факта после сохранения: value, comment, observed_on.
    errors: Partial<Record<'value' | 'comment' | 'observed_on', string>>;
}>();

// Новое значение факта. Сохраняет его страница, вместе с другими фактами.
const draft = defineModel<ObservationDraft>('draft', { required: true });

const id = (field: string) => `fact_${props.type.code}_${field}`;

function cancel(): void {
    draft.value = { value: '', comment: '', observed_on: props.today };
}
</script>

<template>
    <!-- Строка факта: слева текущее значение, справа новое. Дата и комментарий — после выбора значения. -->
    <li
        class="grid gap-x-6 gap-y-2 p-4 @lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)]"
        :class="{ 'bg-accent/40': draft.value !== '' }"
        :data-fact="type.code"
        data-test="observation"
    >
        <div class="flex flex-col gap-0.5 text-sm">
            <h3 :id="id('name')" class="font-medium">{{ type.name }}</h3>
            <p v-if="type.history.length > 0" data-test="observation-current">
                <span class="font-semibold">{{ type.history[0].value }}</span>
                <span v-if="type.history[0].comment">
                    ({{ type.history[0].comment }})</span
                >
                <span class="text-muted-foreground">
                    · {{ type.history[0].observed_on }},
                    {{ type.history[0].recorded_by }}</span
                >
            </p>
            <p v-else class="text-muted-foreground">Не внесено</p>
            <details
                v-if="type.history.length > 1"
                class="text-muted-foreground"
            >
                <summary
                    class="w-fit cursor-pointer rounded-sm hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid pointer-coarse:py-2.5"
                >
                    Прежние значения: {{ type.history.length - 1 }}
                </summary>
                <ul class="mt-1 flex flex-col gap-0.5">
                    <li v-for="fact in type.history.slice(1)" :key="fact.id">
                        {{ fact.value
                        }}{{ fact.comment ? ` (${fact.comment})` : '' }} ·
                        {{ fact.observed_on }}, {{ fact.recorded_by }}
                    </li>
                </ul>
            </details>
        </div>

        <div
            class="flex flex-col gap-3"
            role="group"
            :aria-labelledby="id('name')"
        >
            <div
                v-if="type.value_type === 'boolean'"
                class="flex flex-wrap gap-2"
                role="radiogroup"
                :aria-labelledby="id('name')"
                :aria-describedby="id('value-error')"
            >
                <label
                    v-for="option in [
                        { value: '1', label: 'Да' },
                        { value: '0', label: 'Нет' },
                    ]"
                    :key="option.value"
                    class="flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-input bg-card px-3 text-sm transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid pointer-coarse:min-h-11"
                >
                    <input
                        v-model="draft.value"
                        type="radio"
                        :name="id('value')"
                        :value="option.value"
                        class="size-4 shrink-0 outline-none"
                        :aria-invalid="errors.value ? true : undefined"
                    />
                    {{ option.label }}
                </label>
            </div>

            <div
                v-else-if="type.value_type === 'number'"
                class="flex items-center gap-2"
            >
                <Input
                    :id="id('value')"
                    v-model="draft.value"
                    type="number"
                    inputmode="decimal"
                    step="0.01"
                    :min="type.min ?? undefined"
                    :max="type.max ?? undefined"
                    class="max-w-32"
                    :aria-labelledby="id('name')"
                    :aria-invalid="errors.value ? true : undefined"
                    :aria-describedby="id('value-error')"
                    placeholder="Значение"
                />
                <span class="text-sm text-muted-foreground">{{
                    type.unit
                }}</span>
            </div>

            <NativeSelect
                v-else
                :id="id('value')"
                v-model="draft.value"
                :aria-labelledby="id('name')"
                :aria-invalid="errors.value ? true : undefined"
                :aria-describedby="id('value-error')"
            >
                <option value="" disabled>Выберите значение</option>
                <option
                    v-for="option in type.options ?? []"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </NativeSelect>
            <InputError :id="id('value-error')" :message="errors.value" />

            <div
                v-if="draft.value !== ''"
                class="grid gap-3 rounded-lg bg-muted/60 p-3 @2xl:grid-cols-[10rem_minmax(0,1fr)]"
                data-test="observation-draft"
            >
                <div class="grid content-start gap-2">
                    <Label :for="id('observed_on')">На дату</Label>
                    <Input
                        :id="id('observed_on')"
                        v-model="draft.observed_on"
                        type="date"
                        :max="today"
                        required
                        :aria-invalid="errors.observed_on ? true : undefined"
                        :aria-describedby="id('observed_on-error')"
                    />
                    <InputError
                        :id="id('observed_on-error')"
                        :message="errors.observed_on"
                    />
                </div>
                <div class="grid content-start gap-2">
                    <Label :for="id('comment')"
                        >Комментарий (необязательно)</Label
                    >
                    <Input
                        :id="id('comment')"
                        v-model="draft.comment"
                        :maxlength="commentLimit"
                        :aria-invalid="errors.comment ? true : undefined"
                        :aria-describedby="id('comment-error')"
                    />
                    <InputError
                        :id="id('comment-error')"
                        :message="errors.comment"
                    />
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="justify-self-start @2xl:col-span-2"
                    @click="cancel"
                >
                    Не вносить
                </Button>
            </div>
        </div>
    </li>
</template>
