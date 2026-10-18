<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import InvitationController from '@/actions/App/Http/Controllers/Doctor/InvitationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/doctor/invitations';

type Invitation = {
    id: number;
    patient_name: string | null;
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
const copied = ref(false);
const selected = ref(false);

function resetCopyState(): void {
    copied.value = false;
    selected.value = false;
}

async function copyLink(): Promise<void> {
    if (!invitationUrl.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(invitationUrl.value);
        copied.value = true;
    } catch {
        // Буфер обмена недоступен (например, по http не с localhost): выделяем ссылку для ручного копирования.
        const input = document.getElementById('invitation-link-input');

        if (input instanceof HTMLInputElement) {
            input.select();
            selected.value = true;
        }
    }
}
</script>

<template>
    <Head title="Приглашения" />

    <div class="flex max-w-3xl flex-col gap-8 p-4">
        <Heading
            title="Приглашения"
            description="Пациент регистрируется по ссылке и сразу попадает в список ваших пациентов. Ссылка действует 7 дней и срабатывает один раз."
        />

        <Form
            v-bind="InvitationController.store.form()"
            reset-on-success
            class="flex flex-col gap-4 sm:flex-row sm:items-end"
            v-slot="{ errors, processing }"
            @success="resetCopyState"
        >
            <div class="grid flex-1 gap-2">
                <Label for="patient_name">ФИО пациента (необязательно)</Label>
                <Input
                    id="patient_name"
                    name="patient_name"
                    placeholder="Подставится в форму регистрации"
                />
                <InputError :message="errors.patient_name" />
            </div>
            <Button :disabled="processing" data-test="create-invitation-button">
                Пригласить пациента
            </Button>
        </Form>

        <div
            v-if="invitationUrl"
            class="flex flex-col gap-3 rounded-lg border border-green-600/40 bg-green-50 p-4 dark:bg-green-950/30"
            data-test="invitation-link"
        >
            <p class="text-sm font-medium">
                Отправьте ссылку пациенту. Больше она не покажется: если
                потеряете, создайте новое приглашение.
            </p>
            <div class="flex gap-2">
                <Input
                    id="invitation-link-input"
                    :model-value="invitationUrl"
                    readonly
                    aria-label="Ссылка-приглашение"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <Button type="button" variant="outline" @click="copyLink">
                    <Check v-if="copied" class="size-4" />
                    <Copy v-else class="size-4" />
                    {{ copied ? 'Скопировано' : 'Скопировать' }}
                </Button>
            </div>
            <p v-if="selected" class="text-sm text-muted-foreground">
                Ссылка выделена, скопируйте её вручную (Ctrl+C или ⌘C).
            </p>
        </div>

        <section class="flex flex-col gap-3">
            <h2 class="text-base font-medium">Мои приглашения</h2>

            <p
                v-if="invitations.length === 0"
                class="text-sm text-muted-foreground"
            >
                Приглашений пока нет.
            </p>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Пациент</th>
                            <th class="px-3 py-2 font-medium">Создано</th>
                            <th class="px-3 py-2 font-medium">Действует до</th>
                            <th class="px-3 py-2 font-medium">Статус</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="invitation in invitations"
                            :key="invitation.id"
                            class="border-t"
                            data-test="invitation-row"
                        >
                            <td class="px-3 py-2">
                                {{ invitation.patient_name ?? '—' }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ invitation.created_at }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ invitation.expires_at }}
                            </td>
                            <td class="px-3 py-2">
                                <Badge
                                    :variant="
                                        invitation.status === 'active'
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{ invitation.status_label }}
                                </Badge>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Button
                                    v-if="invitation.can_revoke"
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                >
                                    <Link
                                        :href="
                                            InvitationController.revoke(
                                                invitation.id,
                                            )
                                        "
                                        as="button"
                                        preserve-scroll
                                    >
                                        Отозвать
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
