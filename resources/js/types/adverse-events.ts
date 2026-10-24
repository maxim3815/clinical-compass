/**
 * Группа симптомов в форме пациента: «Бывает часто», «Бывает реже», «Врач получит срочное
 * уведомление» и «Нужна помощь сейчас».
 */
export type SideEffectGroup = {
    level: 'common' | 'uncommon' | 'urgent' | 'emergency';
    label: string;
    symptoms: { id: number; code: string; name: string }[];
};

/**
 * Побочный эффект в списке пациента «Вы сообщали».
 */
export type PatientSideEffect = {
    id: number;
    name: string;
    // «слабо», «мешает», «сильно».
    severity: string;
    // «с 21 октября» или «21–25 октября».
    period: string;
    resolved: boolean;
    // «Уже прошло» отмечено сегодня: можно отменить.
    undoable: boolean;
    // Записал врач со слов пациента.
    by_doctor: boolean;
};

/**
 * Слова листка-вкладыша о приёме препарата при симптоме «нужна помощь сейчас».
 */
export type SideEffectInstruction = {
    drug: string;
    text: string;
    url: string;
};

/**
 * Что пациент видит сразу после отправки: обычный симптом, «сообщить врачу сразу»
 * или «нужна помощь сейчас».
 */
export type SideEffectReport = {
    kind: 'regular' | 'urgent' | 'emergency';
    // Время отправки по часовому поясу пациента.
    at: string;
    instructions: SideEffectInstruction[];
};

/**
 * Нежелательное явление в карточке врача.
 */
export type AdverseEvent = {
    id: number;
    // Термин; у «другого» пусто, тогда главное — слова пациента.
    term: string | null;
    name: string;
    level: 'common' | 'uncommon' | 'urgent' | 'emergency' | null;
    // Как выбрал пациент: «Сильно, не могу жить как обычно».
    severity: string;
    // Коротко для плашки: «сильно».
    severity_short: string;
    started_on: string;
    reported_at: string;
    // Записал врач: ФИО коротко.
    by_doctor: string | null;
    // На фоне каких курсов начался симптом и какой это был день курса.
    medications: { drug: string; day: number }[];
    resolved: { on: string; days: number } | null;
    // Что стало с курсами после записи: изменение, отмена.
    outcomes: string[];
    reviewed: { at: string; by: string | null } | null;
    awaits_review: boolean;
    // Срочный, алерт ещё не закрыт: разбирается решением по алерту.
    alert_open: boolean;
    comment: string | null;
};

/**
 * Симптом из словаря для записи врача.
 */
export type SideEffectOption = {
    id: number;
    name: string;
    term: string;
};
