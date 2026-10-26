<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { UserCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import ReferralController from '@/actions/App/Http/Controllers/Doctor/ReferralController';
import ClinicalProfileSummary from '@/components/ClinicalProfileSummary.vue';
import CurrentMedications from '@/components/CurrentMedications.vue';
import ReferralBasis from '@/components/ReferralBasis.vue';
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
import { Spinner } from '@/components/ui/spinner';
import WellbeingSection from '@/components/WellbeingSection.vue';
import { formatAge } from '@/lib/plural';
import { index } from '@/routes/doctor/patients';
import type {
    ClinicalProfile,
    Prescription,
    Referral,
    ReferralCategory,
    Wellbeing,
} from '@/types';

/**
 * Направление от терапевта до принятия: основание, шкалы, HADS, признаки и комментарий терапевта.
 * «Принять пациента» переводит пациента к психиатру со всей историей.
 */
type Props = {
    referral: Referral;
    patient: {
        id: number;
        name: string;
        birth_date: string | null;
        age: number | null;
    };
    wellbeing: Wellbeing;
    clinicalProfile: ClinicalProfile;
    medications: Prescription[];
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: Props) => ({
        breadcrumbs: [
            { title: 'Пациенты', href: index() },
            {
                title: `Направление: ${props.patient.name}`,
                href: ReferralController.show(props.referral.id),
            },
        ],
    }),
});

const accepting = ref(false);
const processing = ref(false);

function label(category: ReferralCategory | null): string {
    return category === 'required'
        ? '«обязательно»'
        : category === 'desirable'
          ? '«желательно»'
          : 'направление не подсказывала';
}

const summary = computed(() =>
    [
        props.patient.age === null ? null : formatAge(props.patient.age),
        props.patient.birth_date
            ? `дата рождения ${props.patient.birth_date}`
            : null,
    ]
        .filter(Boolean)
        .join(' · '),
);

const decision = computed(() => {
    if (props.referral.suggested_category === props.referral.category) {
        return 'Решение терапевта совпадает с подсказкой системы.';
    }

    return props.referral.suggested_category === null
        ? `Система направление не подсказывала, решение терапевта: ${label(props.referral.category)}.`
        : `Система подсказывала ${label(props.referral.suggested_category)}, решение терапевта: ${label(props.referral.category)}.`;
});

function accept(): void {
    router.post(
        ReferralController.accept(props.referral.id).url,
        {},
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Head :title="`Направление: ${patient.name}`" />

    <div class="@container flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-1">
                <p class="text-sm text-muted-foreground">
                    Направление от терапевта
                </p>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ patient.name }}
                </h1>
                <p v-if="summary" class="text-sm text-muted-foreground">
                    {{ summary }}
                </p>
            </div>
            <Button data-test="accept-referral" @click="accepting = true">
                <UserCheck />
                Принять пациента
            </Button>
        </header>

        <div
            class="grid grid-cols-[minmax(0,1fr)] items-start gap-6 @4xl:grid-cols-[minmax(0,1fr)_20rem]"
        >
            <div class="flex min-w-0 flex-col gap-6">
                <section
                    class="flex flex-col gap-4 rounded-xl border bg-card p-4 shadow-xs"
                    aria-labelledby="referral-title"
                    data-test="referral"
                >
                    <h2 id="referral-title" class="sr-only">Направление</h2>
                    <ReferralCategoryBadge
                        :category="referral.category"
                        prefix=""
                        large
                        class="self-start"
                    />
                    <p>
                        {{
                            `${referral.from.name}, ${(referral.from.specialty ?? 'терапевт').toLowerCase()}, ${referral.created_at}.`
                        }}
                    </p>
                    <p
                        class="text-sm"
                        :class="
                            referral.suggested_category === referral.category
                                ? 'text-muted-foreground'
                                : 'font-medium'
                        "
                        data-test="referral-decision"
                    >
                        {{ decision }}
                    </p>
                    <p v-if="referral.comment" data-test="referral-comment">
                        <span class="text-muted-foreground"
                            >Комментарий терапевта:
                        </span>
                        {{ referral.comment }}
                    </p>

                    <div class="flex flex-col gap-2">
                        <h3 class="font-semibold">Основание подсказки</h3>
                        <ReferralBasis
                            v-if="referral.basis.length > 0"
                            :matches="referral.basis"
                        />
                        <p v-else class="text-sm text-muted-foreground">
                            Ни одно правило подсказки не сработало: направление
                            по решению терапевта.
                        </p>
                    </div>

                    <!-- Основание и шкалы длинные: на телефоне кнопка нужна и после них. -->
                    <Button
                        class="self-start @4xl:hidden"
                        data-test="accept-referral-bottom"
                        @click="accepting = true"
                    >
                        <UserCheck />
                        Принять пациента
                    </Button>
                </section>

                <WellbeingSection :wellbeing="wellbeing" />
            </div>

            <aside class="flex min-w-0 flex-col gap-6">
                <section
                    class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
                    aria-labelledby="profile-title"
                >
                    <h2 id="profile-title" class="font-semibold">
                        Клинический профиль
                    </h2>
                    <ClinicalProfileSummary :profile="clinicalProfile" />
                </section>
                <section
                    class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
                    aria-labelledby="medications-title"
                >
                    <h2 id="medications-title" class="font-semibold">
                        Препараты
                    </h2>
                    <CurrentMedications :medications="medications" />
                </section>
            </aside>
        </div>

        <Dialog
            :open="accepting"
            @update:open="(value: boolean) => (accepting = value)"
        >
            <DialogContent data-test="accept-referral-dialog">
                <DialogHeader>
                    <DialogTitle>Принять пациента?</DialogTitle>
                    <DialogDescription>
                        {{
                            `${patient.name} перейдёт к вам со всей историей: вы станете лечащим врачом, у пациента появится чат с вами. Терапевт больше не будет видеть карточку. Если не принять сейчас, направление останется в очереди.`
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button
                        :disabled="processing"
                        :aria-busy="processing"
                        data-test="confirm-accept-referral"
                        @click="accept"
                    >
                        <Spinner v-if="processing" />
                        Принять
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
