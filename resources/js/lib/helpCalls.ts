import { http } from '@inertiajs/vue3';
import HelpController from '@/actions/App/Http/Controllers/Patient/HelpController';

export type HelpLine = '112' | 'crisis_line';

/**
 * Пациент нажал звонок в 112 или на кризисную линию: врач увидит это тихой строкой в карточке.
 * Сам звонок совершает телефон по ссылке `tel:`, запрос его не задерживает, а ошибка запроса
 * пациенту не важна.
 */
export function recordHelpCall(line: HelpLine): void {
    http.getClient()
        .request({ ...HelpController.call(), data: { line } })
        .catch(() => {});
}
