import type { Mood } from './moods';

/**
 * Плановый приём на экране «Сегодня», как его отдаёт `App\Support\DoseEventPresenter`.
 */
export type Dose = {
    id: number;
    drug: string;
    dose: string;
    instruction: string | null;
    // «сегодня» или «вчера».
    day: string;
    // Плановое время по поясу пациента: «8:00».
    time: string;
    status: 'scheduled' | 'taken' | 'skipped' | 'missed';
    // «Принят в 8:05», «Не принят: забыл», «Пропущен»; у запланированного null.
    status_text: string | null;
    taken_late: boolean;
    skip_comment: string | null;
    // Когда отмечен, если отметка сделана после закрытия окна: «20.10 в 9:05».
    marked_late: string | null;
    // Что делать при пропуске дозы, у пропущенного приёма.
    missed_dose: string | null;
    // Время приёма подошло, его можно отметить.
    due: boolean;
    due_from: string;
    // Начальные значения формы отметки.
    taken_time: string;
    skip_reason: string | null;
};

export type DoseGroup = {
    time: string;
    // Лицо настроения, которое пациент выбрал после отметки этого времени сегодня.
    mood: Mood | null;
    doses: Dose[];
};

/**
 * Назначение «по необходимости» на экране «Сегодня».
 */
export type AsNeeded = {
    id: number;
    drug: string;
    dose: string;
    instruction: string | null;
    // У препарата, добавленного пациентом, ограничения может не быть.
    max_daily_doses: number | null;
    taken_today: number;
    doses: {
        id: number;
        day: string;
        time: string;
    }[];
};
