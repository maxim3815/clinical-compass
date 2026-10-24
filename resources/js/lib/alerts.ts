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
 * Действие пациента одним словом: «Нажато» у «Сообщить врачу», «Отправлено» у побочного
 * эффекта, «Заполнен» у опросника.
 */
export function alertAction(kind: AlertKind): string {
    return {
        answers: 'Заполнен',
        help_request: 'Нажато',
        adverse_event: 'Отправлено',
    }[kind];
}

/**
 * Когда пациент ответил, нажал «Сообщить врачу» или отправил побочный эффект:
 * «Опросник заполнен 24.10.2026 14:05», «Нажато 24.10.2026 14:05», «Отправлено 24.10.2026 14:05».
 */
export function alertSubmitted(kind: AlertKind, at: string): string {
    return kind === 'answers'
        ? `Опросник заполнен ${at}`
        : `${alertAction(kind)} ${at}`;
}

/**
 * Повторы «Сообщить врачу»: «повторно 24.10.2026 14:20» или «повторно 3 раза, последний
 * 24.10.2026 14:20». У побочного эффекта повтор — новое сообщение, часто о другом симптоме:
 * «новое сообщение 24.10.2026 14:20», «ещё 2 сообщения, последнее 24.10.2026 14:20».
 */
export function alertRepeats(
    repeats: AlertRepeats,
    kind: AlertKind = 'help_request',
): string {
    if (kind === 'adverse_event') {
        return repeats.count === 1
            ? `новое сообщение ${repeats.at}`
            : `ещё ${repeats.count} ${plural(repeats.count, ['сообщение', 'сообщения', 'сообщений'])}, последнее ${repeats.at}`;
    }

    return repeats.count === 1
        ? `повторно ${repeats.at}`
        : `повторно ${repeats.count} ${plural(repeats.count, ['раз', 'раза', 'раз'])}, последний ${repeats.at}`;
}

/**
 * Состояние открытого алерта одинаково в шапке карточки, в очереди и в блоке алерта:
 * «не принят», «повтор не принят» (пациент снова нажал «Сообщить врачу» или сообщил о срочном
 * побочном эффекте после «Принять») или «ждёт решения».
 */
export function alertState(
    awaits: boolean,
    repeated: boolean,
    kind: AlertKind = 'help_request',
): string {
    if (!awaits) {
        return 'ждёт решения';
    }

    if (!repeated) {
        return 'не принят';
    }

    return kind === 'adverse_event'
        ? 'новое сообщение не принято'
        : 'повтор не принят';
}
