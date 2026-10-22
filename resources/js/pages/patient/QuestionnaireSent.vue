<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CircleCheck } from '@lucide/vue';
import { computed, onMounted, useTemplateRef } from 'vue';
import HelpCard from '@/components/HelpCard.vue';
import { Button } from '@/components/ui/button';
import { home, wellbeing } from '@/routes/patient';

const props = defineProps<{
    // Лечащий врач, которому ушли ответы.
    doctor: string | null;
    submittedAt: string;
    // Ответы подпадают под правило алертинга: экран помощи с 112 и чатом.
    help: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Сегодня',
                href: home(),
            },
            {
                title: 'Ответы переданы',
                href: wellbeing(),
            },
        ],
    },
});

// После перехода фокус на заголовке: скринридер сразу сообщает, что ответы ушли.
const heading = useTemplateRef<HTMLElement>('heading');

onMounted(() => heading.value?.focus());

// Одной строкой: текст и <template v-if> подряд на сервере сливаются в один узел. Что врач может
// ответить не сразу, на экране помощи говорит карточка с 112: звонка врача экран не обещает [медик].
const details = computed(() =>
    props.doctor === null
        ? `Отправлено ${props.submittedAt}.`
        : `${props.doctor} увидит их в вашей карточке. Отправлено ${props.submittedAt}.`,
);
</script>

<template>
    <Head title="Ответы переданы врачу" />

    <div class="flex flex-col gap-6 p-4" data-test="questionnaire-sent">
        <section
            class="flex flex-col items-center gap-3 rounded-2xl border bg-card p-6 text-center shadow-xs"
        >
            <CircleCheck class="size-12 text-success" />
            <h1
                ref="heading"
                class="text-2xl font-semibold tracking-tight outline-none"
                tabindex="-1"
            >
                Ответы переданы врачу
            </h1>
            <p class="text-muted-foreground">{{ details }}</p>
        </section>

        <!-- Экран помощи: главное действие — звонок 112, «Готово» уходит вниз. -->
        <HelpCard v-if="help" />

        <div class="flex flex-col gap-3">
            <Button
                :size="help ? 'lg' : 'xl'"
                :variant="help ? 'outline' : 'default'"
                as-child
            >
                <Link :href="home()" data-test="questionnaire-done"
                    >Готово</Link
                >
            </Button>
            <!-- На экране помощи лишний выбор не нужен: только 112, чат и «Готово». -->
            <Button v-if="!help" variant="outline" size="lg" as-child>
                <Link :href="wellbeing()">Посмотреть самочувствие</Link>
            </Button>
        </div>
    </div>
</template>
