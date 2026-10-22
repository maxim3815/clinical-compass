/**
 * Опросники пациента (GAD-7, PHQ-9): календарь, суммы для графика, заполнения у врача.
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

export type DoctorScale = {
    code: string;
    name: string;
    title: string;
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
    score: number;
    label: string;
    // Вопрос, ответ на который врач видит отдельно (9-й PHQ-9).
    highlight: boolean;
};

// Заполнения одной отправки.
export type AssessmentFilling = {
    submitted_at: string;
    assessments: {
        id: number;
        scale: string;
        name: string;
        total: number;
        max: number;
        band: string;
        answers: AssessmentAnswer[];
    }[];
};

export type Wellbeing = {
    status: AssessmentStatus | null;
    scales: DoctorScale[];
    fillings: AssessmentFilling[];
};
