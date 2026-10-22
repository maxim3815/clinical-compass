import { ref } from 'vue';
import type { AppointmentPin, InvitationChanged } from '@/types';

export type AppointmentStage = 'waiting' | 'entered' | 'registered';

// Выданный PIN живёт вне окна «Пригласить на приёме»: врач уходит в карточку другого пациента,
// возвращается, и окно показывает тот же код, который он уже назвал вслух. До перезагрузки
// страницы. Меняется только в браузере: при SSR окно не открывается и код не выдаётся.
const issued = ref<AppointmentPin | null>(null);
const deadline = ref(0);
const stage = ref<AppointmentStage>('waiting');
const patient = ref<InvitationChanged['patient']>(null);

export function useAppointmentPin() {
    return { issued, deadline, stage, patient };
}
