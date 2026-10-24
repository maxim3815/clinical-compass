import { plural } from '@/lib/plural';
import type { AlertKind, AlertRepeats } from '@/types';

/**
 * Алерт у врача одной строкой, одинаково в плашке, очереди и карточке:
 * «Суицидальный риск — PHQ-9, вопрос 9: «почти каждый день»».
 */
export function alertTitle(rule: string, trigger: string): string {
    return `${rule} — ${trigger}`;
}

/**
 * Когда пациент ответил или нажал «Сообщить врачу»: «Опросник заполнен 24.10.2026 14:05»,
 * «Нажато 24.10.2026 14:05».
 */
export function alertSubmitted(kind: AlertKind, at: string): string {
    return kind === 'help_request' ? `Нажато ${at}` : `Опросник заполнен ${at}`;
}

/**
 * Повторные «Сообщить врачу»: «повторно 24.10.2026 14:20» или «повторно 3 раза, последний
 * 24.10.2026 14:20».
 */
export function alertRepeats(repeats: AlertRepeats): string {
    return repeats.count === 1
        ? `повторно ${repeats.at}`
        : `повторно ${repeats.count} ${plural(repeats.count, ['раз', 'раза', 'раз'])}, последний ${repeats.at}`;
}

/**
 * Состояние открытого алерта одинаково в шапке карточки, в очереди и в блоке алерта:
 * «не принят», «повтор не принят» (пациент снова нажал «Сообщить врачу» после «Принять»)
 * или «ждёт решения».
 */
export function alertState(awaits: boolean, repeated: boolean): string {
    if (!awaits) {
        return 'ждёт решения';
    }

    return repeated ? 'повтор не принят' : 'не принят';
}
