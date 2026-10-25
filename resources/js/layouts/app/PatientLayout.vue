<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PatientHeader from '@/components/PatientHeader.vue';
import PatientTabBar from '@/components/PatientTabBar.vue';
import { Toaster } from '@/components/ui/sonner';
import { useNavCounters } from '@/composables/useNavCounters';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

useNavCounters();

const page = usePage();

// На формах панели вкладок нет: случайное касание вкладки не уносит введённое, а выйти
// можно ссылкой назад в шапке или кнопкой «Отмена».
const isForm = computed(() =>
    [
        'patient/MedicationForm',
        'patient/IntakeTimes',
        'patient/Questionnaire',
        'patient/SideEffectForm',
    ].includes(page.component),
);

// Новый пациент ещё не прочитал правила: вкладки только вернули бы его на экран правил.
const showTabBar = computed(() => !isForm.value && !page.props.rulesPending);

// Пока на телефоне открыта клавиатура, панель вкладок прячется: иначе Android поднимает её
// над клавиатурой, и от переписки остаётся пара строк. Клавиатура открыта, когда фокус
// в текстовом поле и видимая часть экрана заметно ниже обычной: «Назад» прячет клавиатуру,
// не снимая фокус.
const fieldFocused = ref(false);
const keyboardOpen = ref(false);
const typing = computed(() => fieldFocused.value && keyboardOpen.value);

let fullHeight = 0;
let lastWidth = 0;

function isTextField(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLTextAreaElement ||
        (target instanceof HTMLInputElement &&
            !['checkbox', 'radio', 'button', 'submit'].includes(target.type))
    );
}

function onFocusIn(event: FocusEvent): void {
    fieldFocused.value =
        window.matchMedia('(pointer: coarse)').matches &&
        isTextField(event.target);
}

function onFocusOut(): void {
    fieldFocused.value = false;
}

function onViewportResize(): void {
    const viewport = window.visualViewport;

    if (viewport === null) {
        return;
    }

    // После поворота экрана обычная высота другая.
    if (viewport.width !== lastWidth) {
        lastWidth = viewport.width;
        fullHeight = viewport.height;
    }

    fullHeight = Math.max(fullHeight, viewport.height);
    keyboardOpen.value = viewport.height < fullHeight * 0.8;
}

onMounted(() => {
    document.addEventListener('focusin', onFocusIn);
    document.addEventListener('focusout', onFocusOut);
    window.visualViewport?.addEventListener('resize', onViewportResize);
    onViewportResize();
});

onBeforeUnmount(() => {
    document.removeEventListener('focusin', onFocusIn);
    document.removeEventListener('focusout', onFocusOut);
    window.visualViewport?.removeEventListener('resize', onViewportResize);
});

// Текст пациента не мельче 16 px, и в уведомлениях тоже.
const toastOptions = {
    classes: { title: 'text-base leading-snug font-medium' },
};
</script>

<template>
    <!--
        Кабинет пациента: узкая колонка, внизу панель вкладок, как в приложениях на телефоне.
        --patient-chrome — сколько экрана занимают шапка, панель и нижний отступ: по нему
        страница чата берёт себе ровно остаток экрана.
    -->
    <div
        class="flex min-h-svh flex-col [--patient-chrome:calc(3.5rem+1px+var(--tab-bar-height)+env(safe-area-inset-bottom)+1rem)]"
        :class="
            typing || !showTabBar
                ? '[--tab-bar-height:0px]'
                : '[--tab-bar-height:4.25rem]'
        "
    >
        <PatientHeader :breadcrumbs="breadcrumbs" />
        <!--
            Панель вкладок закреплена внизу: отступ не даёт ей закрыть конец страницы.
            Подписи и ошибки полей у пациента не мельче 16 px, как и остальной текст.
        -->
        <main
            class="mx-auto w-full max-w-xl flex-1 [&_[data-slot=input-error]]:text-base [&_[data-slot=label]]:text-base"
            :class="
                !showTabBar
                    ? 'pb-[calc(env(safe-area-inset-bottom)+1rem)]'
                    : 'pb-[calc(var(--tab-bar-height)+env(safe-area-inset-bottom)+1rem)]'
            "
        >
            <slot />
        </main>
        <PatientTabBar v-if="showTabBar" v-show="!typing" />
        <Toaster
            position="top-center"
            container-aria-label="Уведомления"
            :toast-options="toastOptions"
        />
    </div>
</template>
