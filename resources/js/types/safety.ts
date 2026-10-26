/**
 * Проверка безопасности назначения, как её отдаёт `App\Support\Safety\SafetyPresenter`.
 */
export type SafetyLevel = 'contraindicated' | 'caution';

export type SafetySource = {
    document: string;
    section: string;
    url: string;
    quote: string | null;
};

/**
 * Правило: что проверяется, уровень и источник.
 */
export type SafetyRuleView = {
    rule: string;
    type_label: string;
    level: SafetyLevel;
    level_label: string;
    text: string;
    advice: string | null;
    source: SafetySource;
};

/**
 * Находка в блоке проверки формы назначения.
 */
export type SafetyResult = SafetyRuleView & {
    key: string;
    // У какого препарата находка: «Эсциталопрам (добавил пациент)».
    target: string;
    evidence: string[];
    // Врач уже решил по ней раньше, обоснование не нужно.
    decided: boolean;
};

export type SafetyReport = {
    // У препарата есть правила: без них проверять нечего.
    has_rules: boolean;
    findings: SafetyResult[];
    // Находки у других препаратов пациента, которые появятся вместе с назначением.
    caused: SafetyResult[];
    // Чего не хватило правилам: «СКФ», «пол», «диагноз».
    unchecked: string[];
    // Препараты пациента не из справочника: с ними сочетания не проверены.
    unknown_drugs: string[];
    requires_justification: boolean;
};

/**
 * Находка на идущем назначении в карточке пациента.
 */
export type SafetyFinding = SafetyRuleView & {
    id: number;
    evidence: string[];
    awaits_decision: boolean;
    // С какими препаратами сочетание: решение относится и к ним.
    related: string[];
    related_ids: number[];
    opened_at: string;
    decision: {
        kind_label: string;
        text: string;
        by: string | null;
        at: string;
    } | null;
};

/**
 * Находка у другого препарата, где препарат из списка препаратов от других врачей среди связанных:
 * своих правил у такого препарата нет.
 */
export type RelatedFinding = {
    id: number;
    level: SafetyLevel;
    awaitsDecision: boolean;
    // Назначение с находкой и его название.
    targetId: number;
    targetName: string;
    // Назначение с находкой выше в карточке.
    above: boolean;
};

export type SafetyCard = {
    findings: SafetyFinding[];
    unchecked: string[];
    unknown_drugs: string[];
};

export type ClinicalDiagnosis = {
    id: number;
    code: string;
    name: string;
    is_main: boolean;
    established_on: string;
    created_by: string;
    resolved_on: string | null;
    resolved_by: string | null;
};

export type ClinicalFact = {
    id: number;
    code: string;
    name: string;
    value: string;
    comment: string | null;
    observed_on: string;
    recorded_by: string;
};

/**
 * Клинический профиль пациента: `App\Support\Safety\ClinicalProfilePresenter`.
 */
export type ClinicalProfile = {
    sex: 'female' | 'male' | null;
    sex_label: string | null;
    diagnoses: ClinicalDiagnosis[];
    facts: ClinicalFact[];
};
