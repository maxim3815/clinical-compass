/**
 * Опросники пациента (GAD-7, PHQ-9) и шкалы врача (HARS у психиатра, HADS у терапевта): календарь,
 * суммы для графика, заполнения у врача.
 */

// Пора ли заполнить опросники по календарю.
export type AssessmentStatus = {
    due: boolean;
    // С какого срока ждут заполнения.
    due_since: string | null;
    last: string | null;
    next: string | null;
};

export type ScaleSource = {
    original?: string;
    validation: string;
    url: string;
    license: string;
    note?: string | null;
};

// Точка графика: дата для расстояния по оси (ISO), подписи и сумма.
export type ScorePoint = {
    id: number;
    iso: string;
    date: string;
    short: string;
    total: number;
};

// Опросник с суммами для графика пациента: без степеней.
export type PatientScale = {
    code: string;
    name: string;
    title: string;
    max: number;
    points: ScorePoint[];
    source: ScaleSource;
};

// Опросник для заполнения.
export type QuestionnaireScale = {
    code: string;
    name: string;
    title: string;
    instruction: string;
    options: { score: number; label: string }[];
    items: string[];
    source: ScaleSource;
};

// Строка «Самочувствия»: шкала или подшкала (у HADS — «HADS, тревога» и «HADS, депрессия»).
export type DoctorScale = {
    code: string;
    // Код и название шкалы: у подшкал HADS общие, по ним один источник на шкалу.
    scale: string;
    scale_name: string;
    name: string;
    title: string;
    // Кто заполняет: пациент сам или врач на приёме (HARS, HADS).
    rater: 'patient' | 'doctor';
    // Какой врач заполняет шкалу врача: HARS — психиатр, HADS — терапевт.
    doctor_kind: 'psychiatrist' | 'therapist' | null;
    max: number;
    // Границы степеней: линии на графике.
    thresholds: number[];
    latest: { total: number; band: string; date: string } | null;
    previous: { total: number; date: string } | null;
    // Первое заполнение, если заполнений больше двух: изменение с начала.
    first: { total: number; date: string } | null;
    points: ScorePoint[];
    source: ScaleSource;
};

export type AssessmentAnswer = {
    item: number;
    text: string;
    // Пояснение к пункту шкалы врача: что в него входит.
    hint?: string | null;
    // Подшкала вопроса: «тревога» или «депрессия» у HADS.
    group?: string | null;
    score: number;
    label: string;
    // Вопрос, ответ на который врач видит отдельно (9-й PHQ-9).
    highlight: boolean;
};

// Заполненный опросник или шкала врача с ответами на каждый вопрос.
export type FilledAssessment = {
    id: number;
    scale: string;
    name: string;
    // Шкалу врача заполнил врач на приёме: ФИО и «терапевт» или «психиатр».
    rated_by: string | null;
    rated_by_role: string | null;
    total: number;
    max: number;
    band: string;
    // У HADS суммы и степени по подшкалам вместо общей.
    subscores: AssessmentSubscore[] | null;
    answers: AssessmentAnswer[];
};

export type AssessmentSubscore = {
    code: string;
    name: string;
    total: number;
    max: number;
    band: string;
};

// Заполнения одной отправки.
export type AssessmentFilling = {
    submitted_at: string;
    assessments: FilledAssessment[];
};

// Шкала врача, которую можно заполнить на приёме.
export type RatableScale = {
    code: string;
    name: string;
};

type ScaleOption = { score: number; label: string };
type ScaleBand = { min: number; max: number; label: string };

// Форма шкалы врача. Варианты общие (HARS: оценки 0–4) или свои у пункта (HADS).
export type DoctorScaleForm = {
    code: string;
    name: string;
    title: string;
    instruction: string;
    // Строка над утверждениями для того, кто отвечает (HADS: «последние 7 дней»).
    prompt: string | null;
    options: ScaleOption[];
    items: {
        text: string;
        hint: string | null;
        options: ScaleOption[] | null;
        subscale: string | null;
    }[];
    max: number;
    bands: ScaleBand[];
    // Подшкалы HADS: свои суммы и степени.
    subscales:
        | { code: string; name: string; max: number; bands: ScaleBand[] }[]
        | null;
    source: { validation: string; url: string; note: string | null };
};

// Признак, который терапевт отмечает вместе с HADS.
export type ScreeningFact = {
    code: string;
    name: string;
    value: boolean | null;
};

export type Wellbeing = {
    status: AssessmentStatus | null;
    scales: DoctorScale[];
    fillings: AssessmentFilling[];
};
