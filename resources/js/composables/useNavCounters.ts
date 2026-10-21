import { router, usePage } from '@inertiajs/vue3';
import { useRealtime } from '@/composables/useRealtime';

/**
 * Счётчики в меню приходят с каждой страницей (shared props), а между переходами —
 * событиями. Подписка живёт, пока на странице каркас кабинета.
 */
export function useNavCounters(): void {
    const user = usePage().props.auth.user;
    const channel = `App.Models.User.${user.id}`;

    useRealtime<{ count: number }>(channel, 'unread.changed', (event) =>
        router.replaceProp('unreadMessages', event.count),
    );

    // Пациенты, которые требуют внимания, есть только у врача.
    if (user.role === 'doctor') {
        useRealtime<{ count: number }>(
            channel,
            'patient-signals.changed',
            (event) => router.replaceProp('patientSignals', event.count),
        );
    }
}
