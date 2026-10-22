import type { SelectOption } from './prescriptions';

/**
 * Непринятый алерт: красная плашка на всех страницах кабинета врача.
 */
export type UrgentAlert = {
    id: number;
    patient_id: number;
    patient: string;
    // Название правила: «Суицидальный риск».
    rule: string;
    // Что сработало: «PHQ-9, вопрос 9: «почти каждый день»».
    trigger: string;
    // Когда пациент ответил, ДД.ММ.ГГГГ ЧЧ:ММ.
    at: string;
    // Плашку уже показали врачу (на этом или другом устройстве).
    shown: boolean;
};

/**
 * Открытый алерт пациента в очереди «Требуют внимания».
 */
export type AttentionAlert = {
    rule: string;
    trigger: string;
    acknowledged: boolean;
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
    // Вопросы целиком с ответом пациента.
    answers: string[];
    source: {
        document: string;
        section: string;
        url: string;
        quote: string | null;
    };
    submitted_at: string;
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
