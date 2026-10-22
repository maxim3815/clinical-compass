<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { Printer, UserPlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import InvitationController from '@/actions/App/Http/Controllers/Doctor/InvitationController';
import AppointmentInvitation from '@/components/AppointmentInvitation.vue';
import InputError from '@/components/InputError.vue';
import OneTimeLink from '@/components/OneTimeLink.vue';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useRealtime } from '@/composables/useRealtime';
import { card, index } from '@/routes/doctor/invitations';

type Invitation = {
    id: number;
    patient_name: string | null;
    // Пациент ввёл PIN на приёме и ещё заполняет форму.
    from_appointment: boolean;
    created_at: string;
    expires_at: string;
    status: 'active' | 'used' | 'expired' | 'revoked';
    status_label: string;
    can_revoke: boolean;
};

defineProps<{
    invitations: Invitation[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Приглашения',
                href: index(),
            },
        ],
    },
});

const page = usePage();
const invitationUrl = computed(() => page.flash.invitationUrl);

// Пациент ввёл код на приёме или зарегистрировался: приглашение появилось или сменило статус.
useRealtime(
    `App.Models.User.${page.props.auth.user.id}`,
    'invitation.changed',
    () => router.reload({ only: ['invitations'] }),
);

// Приглашение, которое врач собрался отозвать; пока null, окно закрыто.
const revoking = ref<Invitation | null>(null);
const revokingNow = ref(false);

function revoke(): void {
    if (revoking.value === null) {
        return;
    }

    router.visit(InvitationController.revoke(revoking.value.id), {
        preserveScroll: true,
        onStart: () => (revokingNow.value = true),
        onFinish: () => {
            revokingNow.value = false;
            revoking.value = null;
        },
    });
}

// Действующее приглашение выделено, остальные спокойные: ими уже ничего не сделать.
function statusVariant(status: Invitation['status']): BadgeVariants['variant'] {
    return status === 'active' ? 'success' : 'muted';
}
</script>

<template>
    <Head title="Приглашения" />

    <div class="@container flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Приглашения</h1>
            <p class="text-muted-foreground">
                Пациент регистрируется по коду на приёме или по ссылке и сразу
                попадает в список ваших пациентов.
            </p>
        </header>

        <section
            class="flex flex-col gap-4 rounded-xl border bg-card p-4 shadow-xs"
            aria-labelledby="appointment-invitation-title"
        >
            <div class="flex flex-col gap-1">
                <h2
                    id="appointment-invitation-title"
                    class="text-lg font-semibold"
                >
                    На приёме
                </h2>
                <p class="text-sm text-muted-foreground">
                    Пациент наводит камеру телефона на QR-код и вводит 4 цифры,
                    которые вы назовёте. Код действует 15 минут и срабатывает
                    один раз.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <AppointmentInvitation variant="default" />
                <Button variant="outline" as-child>
                    <Link :href="card()">
                        <Printer />
                        Карточка для стола
                    </Link>
                </Button>
            </div>
        </section>

        <section
            class="flex flex-col gap-4 rounded-xl border bg-card p-4 shadow-xs"
            aria-labelledby="new-invitation-title"
        >
            <h2 id="new-invitation-title" class="text-lg font-semibold">
                По ссылке
            </h2>
            <Form
                v-bind="InvitationController.store.form()"
                reset-on-success
                class="flex flex-col gap-3 @xl:flex-row @xl:items-end"
                v-slot="{ errors, processing }"
            >
                <div class="grid flex-1 gap-2">
                    <Label for="patient_name"
                        >ФИО пациента (необязательно)</Label
                    >
                    <Input
                        id="patient_name"
                        name="patient_name"
                        placeholder="Подставится в форму регистрации"
                        :aria-invalid="errors.patient_name ? true : undefined"
                        aria-describedby="patient_name-error"
                    />
                    <InputError
                        id="patient_name-error"
                        :message="errors.patient_name"
                    />
                </div>
                <Button
                    :disabled="processing"
                    :aria-busy="processing"
                    data-test="create-invitation-button"
                >
                    <Spinner v-if="processing" />
                    <UserPlus v-else />
                    Пригласить по ссылке
                </Button>
            </Form>
            <p class="text-sm text-muted-foreground">
                Ссылка действует 7 дней и срабатывает один раз.
            </p>

            <OneTimeLink
                v-if="invitationUrl"
                :url="invitationUrl"
                label="Ссылка-приглашение"
                data-test="invitation-link"
            >
                Отправьте ссылку пациенту. Больше она не покажется: если
                потеряете, создайте новое приглашение.
            </OneTimeLink>
        </section>

        <section
            class="flex flex-col gap-3"
            aria-labelledby="invitations-title"
        >
            <h2 id="invitations-title" class="text-lg font-semibold">
                Мои приглашения
                <span
                    v-if="invitations.length > 0"
                    class="font-normal text-muted-foreground"
                    >· {{ invitations.length }}</span
                >
            </h2>

            <p
                v-if="invitations.length === 0"
                class="rounded-xl border border-dashed bg-card p-4 text-muted-foreground"
            >
                Приглашений пока нет.
            </p>

            <!-- На узком месте строка таблицы складывается в карточку. -->
            <div v-else class="rounded-xl border bg-card shadow-xs">
                <table
                    class="w-full text-left text-sm @max-2xl:block @2xl:table-fixed"
                >
                    <thead class="@max-2xl:sr-only">
                        <tr class="border-b text-muted-foreground">
                            <th class="px-4 py-3 font-medium">Пациент</th>
                            <th class="w-[18%] px-4 py-3 font-medium">
                                Создано
                            </th>
                            <th class="w-[18%] px-4 py-3 font-medium">
                                Действует до
                            </th>
                            <th class="w-[16%] px-4 py-3 font-medium">
                                Статус
                            </th>
                            <th class="w-[14%] px-4 py-3">
                                <span class="sr-only">Действия</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="@max-2xl:block">
                        <tr
                            v-for="invitation in invitations"
                            :key="invitation.id"
                            class="border-t first:border-t-0 @max-2xl:flex @max-2xl:flex-wrap @max-2xl:items-center @max-2xl:gap-x-3 @max-2xl:gap-y-1 @max-2xl:p-4"
                            data-test="invitation-row"
                        >
                            <td
                                :id="`invitation-${invitation.id}-name`"
                                class="px-4 py-3 font-medium @max-2xl:w-full @max-2xl:p-0"
                            >
                                {{
                                    invitation.patient_name ??
                                    (invitation.from_appointment
                                        ? 'Пациент с приёма'
                                        : 'Без имени')
                                }}
                            </td>
                            <td
                                class="px-4 py-3 whitespace-nowrap @max-2xl:p-0 @max-2xl:text-muted-foreground"
                            >
                                <span class="@2xl:hidden">создано </span
                                >{{ invitation.created_at }}
                            </td>
                            <td
                                class="px-4 py-3 whitespace-nowrap @max-2xl:w-full @max-2xl:p-0 @max-2xl:text-muted-foreground"
                            >
                                <span class="@2xl:hidden">действует до </span
                                >{{ invitation.expires_at }}
                            </td>
                            <td class="px-4 py-3 @max-2xl:p-0">
                                <Badge
                                    :variant="statusVariant(invitation.status)"
                                >
                                    {{ invitation.status_label }}
                                </Badge>
                            </td>
                            <td
                                class="px-4 py-3 text-right @max-2xl:ml-auto @max-2xl:p-0"
                            >
                                <Button
                                    v-if="invitation.can_revoke"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :aria-describedby="`invitation-${invitation.id}-name`"
                                    data-test="revoke-invitation"
                                    @click="revoking = invitation"
                                >
                                    Отозвать
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Отзыв необратим: ссылка, которую врач уже отправил, перестанет работать. -->
        <Dialog
            :open="revoking !== null"
            @update:open="(value: boolean) => !value && (revoking = null)"
        >
            <DialogContent data-test="revoke-invitation-dialog">
                <DialogHeader>
                    <DialogTitle>Отозвать приглашение?</DialogTitle>
                    <DialogDescription>
                        {{
                            revoking?.patient_name
                                ? `Ссылка для пациента ${revoking.patient_name} перестанет работать.`
                                : 'Ссылка перестанет работать.'
                        }}
                        Чтобы пригласить пациента снова, создайте новое
                        приглашение.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline">
                            Не отзывать
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="revokingNow"
                        data-test="confirm-revoke-invitation"
                        @click="revoke"
                    >
                        <Spinner v-if="revokingNow" />
                        Отозвать
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
