import { router } from '@inertiajs/vue3';
import { unsubscribeBeforeLogout } from '@/lib/push';
import { logout } from '@/routes';

// Сначала снимаем подписку на пуши этого браузера: на общем устройстве уведомления
// не должны приходить прежнему пользователю.
export async function signOut(): Promise<void> {
    await unsubscribeBeforeLogout();
    router.flushAll();
    router.visit(logout());
}
