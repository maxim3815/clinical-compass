<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    MessageCircle,
    OctagonAlert,
    Search,
    Siren,
    UserPlus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AdherenceSignal from '@/components/AdherenceSignal.vue';
import AppointmentInvitation from '@/components/AppointmentInvitation.vue';
import AttentionQueue from '@/components/AttentionQueue.vue';
import ListPagination from '@/components/ListPagination.vue';
import PushBanner from '@/components/PushBanner.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useRealtime } from '@/composables/useRealtime';
import { CHIP_ICON, DANGER_CHIP, MESSAGE_CHIP } from '@/lib/chips';
import { formatAge, plural } from '@/lib/plural';
import { index as invitations } from '@/routes/doctor/invitations';
import { index, show } from '@/routes/doctor/patients';
import { show as chat } from '@/routes/doctor/patients/chat';
import type {
    AttentionPatient,
    InvitationChanged,
    Paginated,
    PatientRow,
} from '@/types';

// По доле за 7 дней: сначала самые низкие или самые высокие. null — по ФИО.
type Sort = 'adherence' | '-adherence' | null;

const props = defineProps<{
    patients: Paginated<PatientRow>;
    // Очередь «Требуют внимания»; при поиске пустая.
    attention: AttentionPatient[];
    search: string;
    sort: Sort;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Пациенты',
                href: index(),
            },
        ],
    },
});

const page = usePage();
const query = ref(props.search);

// Сортировка сохраняет поиск, а поиск — сортировку.
const sorted = (sort: Sort) =>
    index({ query: { search: props.search || undefined, sort } });

const byName = computed(() => sorted(null));
const byAdherence = computed(() =>
    sorted(props.sort === 'adherence' ? '-adherence' : 'adherence'),
);

const adherenceAriaSort = computed(() =>
    props.sort === 'adherence'
        ? 'ascending'
        : props.sort === '-adherence'
          ? 'descending'
          : undefined,
);

// На телефоне заголовков таблицы нет: сортировка — списком над ней.
const sortChoice = computed({
    get: () => props.sort ?? 'name',
    set: (value: string) =>
        router.visit(sorted(value === 'name' ? null : (value as Sort)), {
            preserveScroll: true,
        }),
});

function submit(): void {
    const search = query.value.trim();

    router.get(
        index.url(),
        { search: search || undefined, sort: props.sort ?? undefined },
        {
            preserveState: true,
            replace: true,
        },
    );
}

function messages(count: number): string {
    return `${count} ${plural(count, ['новое', 'новых', 'новых'])}`;
}

const channel = `App.Models.User.${page.props.auth.user.id}`;

// Пациент отметил приём или добавил препарат, scheduler отметил пропуск: доли, сигналы
// и отметки о противопоказаниях в строках и в очереди меняются.
useRealtime(channel, 'patient-signals.changed', () =>
    router.reload({ only: ['patients', 'attention'] }),
);

// Алерт появился, принят или закрыт: пациент в очереди первым или ушёл из неё. Плашку
// и число в меню перезагрузка тоже берёт с сервера: её ответ приходит после того, как плашка
// обновилась событием, и со старыми значениями перетёр бы их.
useRealtime(channel, 'alerts.changed', () =>
    router.reload({
        only: ['patients', 'attention', 'urgentAlerts', 'patientSignals'],
    }),
);

// Новое или прочитанное сообщение меняет число непрочитанных у пациента.
useRealtime(channel, 'unread.changed', () =>
    router.reload({ only: ['patients', 'attention'] }),
);

// Пациент зарегистрировался по приглашению: он появляется в списке, даже если окно
// «Пригласить на приёме» уже закрыто.
useRealtime<InvitationChanged>(channel, 'invitation.changed', (event) => {
    if (event.stage === 'registered') {
        router.reload({ only: ['patients', 'attention'] });
    }
});
</script>

<template>
    <Head title="Пациенты" />

    <div class="@container flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight">Пациенты</h1>
            <!-- Одно окно приглашения на всю страницу: при первой регистрации пустое состояние
                 пропадает, а окно с «зарегистрирован» остаётся открытым. -->
            <div class="flex flex-wrap gap-2">
                <AppointmentInvitation
                    :variant="
                        patients.total === 0 && !search ? 'default' : 'outline'
                    "
                />
                <Button variant="outline" as-child>
                    <Link :href="invitations()">
                        <UserPlus />
                        Пригласить по ссылке
                    </Link>
                </Button>
            </div>
        </header>

        <PushBanner />

        <div
            v-if="patients.total === 0 && !search"
            class="rounded-xl border border-dashed bg-card p-6"
            data-test="patients-empty"
        >
            <p class="text-muted-foreground">
                Пациентов пока нет. Пригласите пациента кнопками выше: он
                зарегистрируется по коду на приёме или по ссылке и появится в
                этом списке.
            </p>
        </div>

        <template v-else>
            <AttentionQueue v-if="!search" :patients="attention" />

            <section
                class="flex flex-col gap-3"
                aria-labelledby="all-patients-title"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="all-patients-title" class="text-lg font-semibold">
                        {{ search ? 'Результаты поиска' : 'Все пациенты' }}
                        <span class="font-normal text-muted-foreground"
                            >· {{ patients.total }}</span
                        >
                    </h2>
                    <form
                        role="search"
                        class="flex w-full gap-2 sm:w-auto"
                        @submit.prevent="submit"
                    >
                        <Input
                            v-model="query"
                            type="search"
                            name="search"
                            class="sm:w-72"
                            placeholder="Поиск по ФИО"
                            aria-label="Поиск по ФИО"
                        />
                        <Button type="submit" variant="outline">
                            <Search />
                            Найти
                        </Button>
                    </form>
                </div>

                <p v-if="patients.total === 0" class="text-muted-foreground">
                    По запросу «{{ search }}» никого не нашли.
                    <Link
                        :href="index()"
                        class="text-foreground underline underline-offset-4"
                    >
                        Показать всех
                    </Link>
                </p>

                <template v-else>
                    <div class="flex items-center gap-2 @2xl:hidden">
                        <label
                            for="patients-sort"
                            class="shrink-0 text-sm text-muted-foreground"
                            >Сортировка</label
                        >
                        <NativeSelect id="patients-sort" v-model="sortChoice">
                            <option value="name">По ФИО</option>
                            <option value="adherence">
                                Сначала худший приём
                            </option>
                            <option value="-adherence">
                                Сначала лучший приём
                            </option>
                        </NativeSelect>
                    </div>

                    <!-- На телефоне строка таблицы складывается в карточку. -->
                    <div
                        class="rounded-xl border bg-card shadow-xs @2xl:overflow-x-auto"
                    >
                        <table
                            class="w-full text-left text-sm @max-2xl:block @2xl:table-fixed"
                        >
                            <thead class="@max-2xl:sr-only">
                                <tr class="border-b text-muted-foreground">
                                    <th
                                        class="w-[31%] px-4 py-3 font-medium"
                                        :aria-sort="
                                            sort === null
                                                ? 'ascending'
                                                : undefined
                                        "
                                    >
                                        <Link
                                            :href="byName"
                                            preserve-scroll
                                            class="inline-flex items-center gap-1 hover:text-foreground"
                                            data-test="sort-name"
                                        >
                                            Пациент
                                            <ArrowUp
                                                v-if="sort === null"
                                                class="size-3.5"
                                            />
                                        </Link>
                                    </th>
                                    <th
                                        class="w-[18%] px-4 py-3 font-medium"
                                        :aria-sort="adherenceAriaSort"
                                    >
                                        <Link
                                            :href="byAdherence"
                                            preserve-scroll
                                            class="inline-flex items-center gap-1 whitespace-nowrap hover:text-foreground"
                                            data-test="sort-adherence"
                                        >
                                            Приём за 7 дней
                                            <ArrowUp
                                                v-if="sort === 'adherence'"
                                                class="size-3.5"
                                            />
                                            <ArrowDown
                                                v-else-if="
                                                    sort === '-adherence'
                                                "
                                                class="size-3.5"
                                            />
                                            <ArrowUpDown
                                                v-else
                                                class="size-3.5"
                                            />
                                        </Link>
                                    </th>
                                    <th class="px-4 py-3 font-medium">
                                        Внимание
                                    </th>
                                    <th class="w-[9%] px-4 py-3 font-medium">
                                        Возраст
                                    </th>
                                    <th class="w-[16%] px-4 py-3 font-medium">
                                        Под наблюдением с
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="@max-2xl:block">
                                <tr
                                    v-for="patient in patients.data"
                                    :key="patient.id"
                                    class="relative border-t transition-colors first:border-t-0 hover:bg-accent/50 has-[a:focus-visible]:bg-accent/50 @max-2xl:flex @max-2xl:flex-wrap @max-2xl:items-center @max-2xl:gap-x-3 @max-2xl:gap-y-1 @max-2xl:p-4"
                                    data-test="patient-row"
                                    :data-signal="
                                        patient.adherence.raised || undefined
                                    "
                                    :data-contraindication="
                                        patient.contraindications > 0 ||
                                        undefined
                                    "
                                >
                                    <td
                                        class="px-4 py-3 @max-2xl:w-full @max-2xl:min-w-0 @max-2xl:p-0"
                                    >
                                        <!-- Ссылка растянута на всю строку: карточка открывается щелчком в любом месте. -->
                                        <Link
                                            :href="show(patient.id)"
                                            class="font-medium underline-offset-4 after:absolute after:inset-0 hover:underline"
                                        >
                                            {{ patient.name }}
                                        </Link>
                                        <p
                                            class="truncate text-muted-foreground"
                                            :title="
                                                patient.diagnosis
                                                    ? `${patient.diagnosis.code} ${patient.diagnosis.name}`
                                                    : undefined
                                            "
                                        >
                                            <span
                                                v-if="patient.age !== null"
                                                class="@2xl:hidden"
                                                >{{ formatAge(patient.age) }} ·
                                            </span>
                                            <template v-if="patient.diagnosis"
                                                >{{ patient.diagnosis.code }}
                                                {{
                                                    patient.diagnosis.name
                                                }}</template
                                            >
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 @max-2xl:p-0">
                                        <span
                                            class="mr-1 text-muted-foreground @2xl:hidden"
                                            >Приём за 7 дней:</span
                                        >
                                        <AdherenceSignal
                                            :signal="patient.adherence"
                                        />
                                    </td>
                                    <td
                                        class="px-4 py-3 @max-2xl:w-full @max-2xl:p-0 @max-2xl:empty:hidden"
                                    >
                                        <span
                                            v-if="
                                                patient.alert ||
                                                patient.contraindications > 0 ||
                                                patient.unread > 0
                                            "
                                            class="inline-flex flex-wrap items-center gap-1.5"
                                        >
                                            <span
                                                v-if="patient.alert"
                                                :class="DANGER_CHIP"
                                                data-test="alert-mark"
                                            >
                                                <Siren :class="CHIP_ICON" />
                                                Алерт
                                            </span>
                                            <span
                                                v-if="
                                                    patient.contraindications >
                                                    0
                                                "
                                                :class="DANGER_CHIP"
                                                data-test="contraindication-mark"
                                            >
                                                <OctagonAlert
                                                    :class="CHIP_ICON"
                                                />
                                                Противопоказание без решения
                                            </span>
                                            <Link
                                                v-if="patient.unread > 0"
                                                :href="chat(patient.id)"
                                                class="relative z-10 whitespace-nowrap pointer-coarse:min-h-11 pointer-coarse:px-3"
                                                :class="MESSAGE_CHIP"
                                                :aria-label="`Сообщения: ${messages(patient.unread)}, ${patient.name}`"
                                                data-test="unread-mark"
                                            >
                                                <MessageCircle
                                                    class="size-4 shrink-0"
                                                />
                                                {{ messages(patient.unread) }}
                                            </Link>
                                        </span>
                                    </td>
                                    <td
                                        class="px-4 py-3 whitespace-nowrap @max-2xl:hidden"
                                    >
                                        {{
                                            patient.age === null
                                                ? '—'
                                                : formatAge(patient.age)
                                        }}
                                    </td>
                                    <td
                                        class="px-4 py-3 whitespace-nowrap @max-2xl:p-0 @max-2xl:text-muted-foreground"
                                    >
                                        <span class="@2xl:hidden"
                                            >под наблюдением с
                                        </span>
                                        {{ patient.started_at }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <ListPagination :page="patients" />
                </template>
            </section>
        </template>
    </div>
</template>
