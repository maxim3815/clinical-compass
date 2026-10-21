<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import ClinicalProfileController from '@/actions/App/Http/Controllers/Doctor/ClinicalProfileController';
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

const props = defineProps<{
    patientId: number;
    type: ObservationTypeOption;
    today: string;
    commentLimit: number;
}>();

const form = useForm<{
    observation_type_id: number;
    // Пусто, пока врач не выбрал значение: «1»/«0», число или вариант из списка.
    value: string;
    comment: string;
    observed_on: string;
}>({
    observation_type_id: props.type.id,
    value: '',
    comment: '',
    observed_on: props.today,
});

const id = (field: string) => `fact_${props.type.code}_${field}`;

function submit(): void {
    form.submit(ClinicalProfileController.storeObservation(props.patientId), {
        preserveScroll: true,
        onSuccess: () => form.reset('value', 'comment'),
    });
}
</script>

<template>
    <article
        class="flex flex-col gap-3 rounded-lg border p-4"
        :data-fact="type.code"
        data-test="observation"
    >
        <header class="flex flex-col gap-1">
            <h3 class="font-medium">{{ type.name }}</h3>
            <p
                v-if="type.history.length > 0"
                class="text-sm"
                data-test="observation-current"
            >
                Сейчас:
                <span class="font-medium">{{ type.history[0].value }}</span>
                <span v-if="type.history[0].comment">
                    ({{ type.history[0].comment }})</span
                >
                <span class="text-muted-foreground">
                    · {{ type.history[0].observed_on }},
                    {{ type.history[0].recorded_by }}</span
                >
            </p>
            <p v-else class="text-sm text-muted-foreground">Не внесено.</p>
        </header>

        <form
            class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem]"
            @submit.prevent="submit"
        >
            <div class="grid content-start gap-2">
                <span
                    v-if="type.value_type === 'boolean'"
                    class="text-sm font-medium"
                    >Значение</span
                >
                <Label v-else :for="id('value')">Значение</Label>

                <div
                    v-if="type.value_type === 'boolean'"
                    class="flex gap-4 text-sm"
                >
                    <label class="flex items-center gap-2">
                        <input
                            v-model="form.value"
                            type="radio"
                            :name="id('value')"
                            value="1"
                        />
                        Да
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="form.value"
                            type="radio"
                            :name="id('value')"
                            value="0"
                        />
                        Нет
                    </label>
                </div>

                <div
                    v-else-if="type.value_type === 'number'"
                    class="flex items-center gap-2"
                >
                    <Input
                        :id="id('value')"
                        v-model="form.value"
                        type="number"
                        inputmode="decimal"
                        step="0.01"
                        :min="type.min ?? undefined"
                        :max="type.max ?? undefined"
                        class="max-w-32"
                    />
                    <span class="text-sm text-muted-foreground">{{
                        type.unit
                    }}</span>
                </div>

                <NativeSelect v-else :id="id('value')" v-model="form.value">
                    <option value="" disabled>Выберите</option>
                    <option
                        v-for="option in type.options ?? []"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </NativeSelect>
                <InputError :message="form.errors.value" />
            </div>

            <div class="grid content-start gap-2">
                <Label :for="id('observed_on')">На дату</Label>
                <Input
                    :id="id('observed_on')"
                    v-model="form.observed_on"
                    type="date"
                    :max="today"
                    required
                />
                <InputError :message="form.errors.observed_on" />
            </div>

            <div class="grid gap-2 sm:col-span-2">
                <Label :for="id('comment')">Комментарий (необязательно)</Label>
                <Input
                    :id="id('comment')"
                    v-model="form.comment"
                    :maxlength="commentLimit"
                    placeholder="Например: 7 недель"
                />
                <InputError :message="form.errors.comment" />
            </div>

            <div class="sm:col-span-2">
                <Button
                    type="submit"
                    size="sm"
                    :disabled="form.processing || form.value === ''"
                    data-test="save-observation"
                >
                    Сохранить
                </Button>
            </div>
        </form>

        <details
            v-if="type.history.length > 1"
            class="text-sm text-muted-foreground"
        >
            <summary class="cursor-pointer">Прежние значения</summary>
            <ul class="mt-2 flex flex-col gap-1">
                <li v-for="fact in type.history.slice(1)" :key="fact.id">
                    {{ fact.value
                    }}{{ fact.comment ? ` (${fact.comment})` : '' }} ·
                    {{ fact.observed_on }}, {{ fact.recorded_by }}
                </li>
            </ul>
        </details>
    </article>
</template>
