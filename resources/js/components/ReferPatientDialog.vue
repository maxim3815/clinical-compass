<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ClipboardPen } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import DoctorScaleController from '@/actions/App/Http/Controllers/Doctor/DoctorScaleController';
import ReferralController from '@/actions/App/Http/Controllers/Doctor/ReferralController';
import InputError from '@/components/InputError.vue';
import ReferralCategoryBadge from '@/components/ReferralCategoryBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { ReferralCategory } from '@/types';

/**
 * Направить к психиатру: кому, категория (по умолчанию подсказанная) и комментарий. Если решение
 * расходится с подсказкой, комментарий обязателен.
 */
const props = defineProps<{
    patientId: number;
    patientName: string;
    suggestion: ReferralCategory | null;
    psychiatrists: { id: number; name: string }[];
    // Шкалы врача, без которых подсказка может быть ниже (HADS сегодня не заполнена).
    missingScales: { scale: string; name: string }[];
    maxLength: number;
}>();

const emit = defineEmits<{
    referred: [];
    closeAutoFocus: [event: Event];
}>();

const open = defineModel<boolean>('open', { required: true });

// Подсказка изменилась, пока окно открыто (пациент отправил ответы): категория — новая подсказка.
const suggestionChanged = ref(false);

const CATEGORIES: ReferralCategory[] = ['required', 'desirable'];

const form = useForm<{
    to_doctor_id: number | null;
    category: ReferralCategory | null;
    comment: string;
    suggested: ReferralCategory | null;
}>({
    to_doctor_id: null,
    category: null,
    comment: '',
    suggested: null,
});

// Каждое открытие — с подсказки, которая сейчас в карточке. Единственного психиатра выбирать не нужно.
watch(
    open,
    (value) => {
        if (value) {
            form.defaults({
                to_doctor_id:
                    props.psychiatrists.length === 1
                        ? props.psychiatrists[0].id
                        : null,
                category: props.suggestion,
                comment: '',
                suggested: props.suggestion,
            });
            form.reset();
            form.clearErrors();
            suggestionChanged.value = false;
        }
    },
    { immediate: true },
);

// Выбор терапевта не затирается: категория меняется, только если он оставил прежнюю подсказку.
watch(
    () => props.suggestion,
    (suggestion, previous) => {
        if (open.value) {
            if (form.category === previous) {
                form.category = suggestion;
            }

            form.suggested = suggestion;
            suggestionChanged.value = true;
        }
    },
);

const differs = computed(
    () => form.category !== null && form.category !== props.suggestion,
);

function focusError(): void {
    document
        .querySelector<HTMLElement>(
            '[data-test="refer-dialog"] [aria-invalid="true"]',
        )
        ?.focus();
}

// Все пропуски сразу, а не по одному за отправку.
function submit(): void {
    form.clearErrors();

    if (form.to_doctor_id === null) {
        form.setError('to_doctor_id', 'Выберите психиатра.');
    }

    if (form.category === null) {
        form.setError('category', 'Выберите категорию.');
    } else if (differs.value && form.comment.trim() === '') {
        form.setError(
            'comment',
            props.suggestion === null
                ? 'Система направление не подсказывает: напишите, почему направляете.'
                : 'Решение расходится с подсказкой: напишите почему.',
        );
    }

    if (form.hasErrors) {
        void nextTick(focusError);

        return;
    }

    form.submit(ReferralController.store(props.patientId), {
        preserveScroll: true,
        onSuccess: () => {
            emit('referred');
            open.value = false;
        },
        onError: focusError,
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            data-test="refer-dialog"
            @close-auto-focus="(event: Event) => emit('closeAutoFocus', event)"
        >
            <form
                class="flex flex-col gap-4"
                novalidate
                @submit.prevent="submit"
            >
                <DialogHeader>
                    <DialogTitle>Направить к психиатру</DialogTitle>
                    <DialogDescription>
                        {{
                            `${patientName}. Психиатр увидит направление в очереди «Требуют внимания» и примет пациента. До этого пациента ведёте вы.`
                        }}
                    </DialogDescription>
                </DialogHeader>

                <!-- Контейнер в DOM всегда: скринридеры зачитывают изменение текста, а не появление узла. -->
                <div role="status">
                    <p
                        v-if="suggestionChanged"
                        class="rounded-lg bg-warning-subtle p-3 text-sm text-warning"
                        data-test="refer-suggestion-changed"
                    >
                        {{
                            `Пока окно было открыто, пришли новые ответы пациента: теперь подсказка ${suggestion === 'required' ? '«обязательно»' : suggestion === 'desirable' ? '«желательно»' : 'не направлять'}.`
                        }}
                    </p>
                </div>

                <div
                    v-if="missingScales.length > 0"
                    class="flex flex-col items-start gap-2 rounded-lg bg-muted p-3 text-sm"
                    data-test="refer-missing"
                >
                    <p>
                        {{
                            `${missingScales.map((scale) => scale.name).join(', ')} сегодня не заполнена: по ней категория может быть выше.`
                        }}
                    </p>
                    <Link
                        v-for="scale in missingScales"
                        :key="scale.scale"
                        :href="
                            DoctorScaleController.create({
                                patient: patientId,
                                scale: scale.scale,
                            })
                        "
                        class="inline-flex min-h-11 items-center gap-1.5 font-medium text-primary underline underline-offset-4"
                    >
                        <ClipboardPen class="size-4" />
                        {{ `Сначала заполнить ${scale.name}` }}
                    </Link>
                </div>

                <p
                    v-if="psychiatrists.length === 0"
                    class="text-sm text-muted-foreground"
                    data-test="refer-no-psychiatrists"
                >
                    В системе пока нет психиатров: направить не к кому.
                </p>

                <div v-else class="grid gap-2">
                    <Label for="refer_to_doctor">Психиатр</Label>
                    <NativeSelect
                        id="refer_to_doctor"
                        v-model="form.to_doctor_id"
                        name="to_doctor_id"
                        :aria-invalid="
                            form.errors.to_doctor_id ? true : undefined
                        "
                        aria-describedby="refer_to_doctor-error"
                        data-test="refer-to-doctor"
                    >
                        <option :value="null" disabled>
                            Выберите психиатра
                        </option>
                        <option
                            v-for="doctor in psychiatrists"
                            :key="doctor.id"
                            :value="doctor.id"
                        >
                            {{ doctor.name }}
                        </option>
                    </NativeSelect>
                    <InputError
                        id="refer_to_doctor-error"
                        :message="form.errors.to_doctor_id"
                    />
                </div>

                <fieldset
                    class="flex flex-col gap-2"
                    aria-describedby="refer_category-error"
                >
                    <legend class="mb-2 text-sm font-medium">Категория</legend>
                    <label
                        v-for="category in CATEGORIES"
                        :key="category"
                        class="flex min-h-11 cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-input bg-card px-3 py-2 text-sm transition-colors hover:border-primary/60 has-checked:border-primary has-checked:bg-secondary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring has-focus-visible:outline-solid"
                        :data-test="`refer-category-${category}`"
                    >
                        <input
                            v-model="form.category"
                            type="radio"
                            name="category"
                            :value="category"
                            class="size-4 shrink-0 outline-none"
                            :aria-invalid="
                                form.errors.category ? true : undefined
                            "
                        />
                        <ReferralCategoryBadge :category="category" prefix="" />
                        <span
                            v-if="category === suggestion"
                            class="text-muted-foreground"
                            >подсказка системы</span
                        >
                    </label>
                    <InputError
                        id="refer_category-error"
                        :message="form.errors.category"
                    />
                </fieldset>

                <div class="grid gap-2">
                    <Label for="refer_comment">
                        {{
                            differs
                                ? suggestion === null
                                    ? 'Комментарий (обязательно): система направление не подсказывает'
                                    : 'Комментарий (обязательно): почему не так, как подсказывает система'
                                : 'Комментарий для психиатра (необязательно)'
                        }}
                    </Label>
                    <Textarea
                        id="refer_comment"
                        v-model="form.comment"
                        name="comment"
                        rows="3"
                        :maxlength="maxLength"
                        :aria-required="differs"
                        :aria-invalid="form.errors.comment ? true : undefined"
                        aria-describedby="refer_comment-hint refer_comment-error"
                        data-test="refer-comment"
                    />
                    <p
                        id="refer_comment-hint"
                        class="text-sm text-muted-foreground"
                    >
                        До {{ maxLength }} символов. Психиатр увидит подсказку
                        системы, ваше решение и комментарий.
                    </p>
                    <InputError
                        id="refer_comment-error"
                        :message="form.errors.comment"
                    />
                </div>

                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="
                            form.processing || psychiatrists.length === 0
                        "
                        :aria-busy="form.processing"
                        data-test="confirm-refer"
                    >
                        <Spinner v-if="form.processing" />
                        Направить
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
