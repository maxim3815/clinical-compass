import type { SelectOption } from './prescriptions';

/**
 * Вид алерта: сработало правило по ответам опросника, пациент нажал «Сообщить врачу»
 * или сообщил о срочном побочном эффекте.
 */
export type AlertKind = 'answers' | 'help_request' | 'adverse_event';

/**
 * Повторы, пока алерт открыт: «Сообщить врачу» или новый срочный побочный эффект.
 */
export type AlertRepeats = {
    count: number;
    at: string;
};

/**
 * Алерт, который ждёт «Принять»: красная плашка на всех страницах кабинета врача.
 */
export type UrgentAlert = {
    id: number;
    patient_id: number;
    patient: string;
    // Название правила: «Суицидальный риск».
    rule: string;
    // Что сработало: «PHQ-9, вопрос 9: «почти каждый день»».
    trigger: string;
    kind: AlertKind;
    // Когда пациент ответил или нажал кнопку, ДД.ММ.ГГГГ ЧЧ:ММ.
    at: string;
    repeats: AlertRepeats | null;
    // Плашку уже показали врачу (на этом или другом устройстве).
    shown: boolean;
};

/**
 * Кризисная линия на экране помощи пациента.
 */
export type CrisisLine = {
    // Номер для показа: «8-800-333-44-34».
    label: string;
    // Ссылка для звонка: «tel:88003334434».
    href: string;
};

/**
 * Тихая строка в карточке пациента: открыт экран помощи или нажат звонок.
 */
export type HelpEvent = {
    at: string;
    label: string;
};

/**
 * Открытый алерт пациента в очереди «Требуют внимания».
 */
export type AttentionAlert = {
    rule: string;
    trigger: string;
    kind: AlertKind;
    // Принят и после этого не было повтора.
    acknowledged: boolean;
    // Пациент снова нажимал «Сообщить врачу» или сообщал о срочном побочном эффекте после «Принять».
    repeated: boolean;
};

/**
 * Алерт в карточке пациента: что сработало, как доставлен, кто принял и чем закрыт.
 */
export type PatientAlert = {
    id: number;
    rule: string;
    // Что сработало и что делать, для врача.
    text: string;
    trigger: string;
    // Вопросы целиком с ответом пациента. У алерта по кнопке пусто.
    answers: string[];
    // Срочные побочные эффекты: термин, слова пациента и что ему показано.
    symptoms: string[];
    // У правила по кнопке источника может не быть.
    source: {
        document: string;
        section: string;
        url: string;
        quote: string | null;
    } | null;
    kind: AlertKind;
    submitted_at: string;
    repeats: AlertRepeats | null;
    // Не принят или пациент снова нажал «Сообщить врачу» после «Принять».
    awaits_acknowledgement: boolean;
    shown: {
        at: string;
        // Через сколько после ответа пациента: «1,2 с».
        after: string;
        // Показан событием в открытом кабинете, а не при открытии страницы.
        realtime: boolean;
    } | null;
    pushed: boolean;
    acknowledged: { at: string; by: string | null } | null;
    closed: {
        at: string;
        by: string | null;
        decision: string;
        comment: string | null;
    } | null;
};

/**
 * Решение, которым врач закрывает алерт.
 */
export type AlertDecisionOption = SelectOption & {
    comment_required: boolean;
};
