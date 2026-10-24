/**
 * Блок «Приём препаратов» в карточке пациента у врача, как его отдаёт
 * `App\Support\Adherence\PatientAdherence`. Всё посчитано на сервере.
 */
export type Adherence = {
    // Часовой пояс пациента, если он не московский: «Самара (UTC+4)».
    timezone: string | null;
    // Действующие курсы врача.
    courses: AdherenceCourse[];
    // Завершённые курсы врача, сначала закончившиеся последними.
    finished: AdherenceCourse[];
    // Действующие курсы препаратов, которые пациент добавил сам.
    own: AdherenceCourse[];
};

type CourseBase = {
    // Первая версия назначения курса.
    id: number;
    drug: string;
    kind_label: string;
    source: 'doctor' | 'patient';
    finished: boolean;
    // «1 октября», не в этом году — «1 октября 2025».
    started: string;
    // Последний день завершённого курса, у действующего null.
    ended: string | null;
    // День курса сегодня, у завершённого null.
    day: number | null;
    // У действующего регулярного курса есть приём без времени.
    missing_times: boolean;
};

type PeriodBase = {
    // «Дни 8–14», «День 8».
    days: string;
    // «8–14 октября».
    dates: string;
    // Период идёт сейчас.
    current: boolean;
};

type DayBase = {
    // Местная дата пациента: «2026-10-20».
    date: string;
    // «20 октября».
    label: string;
    day_of_month: number;
    today: boolean;
};

export type RegularPeriod = PeriodBase & {
    // Доля принятых в процентах или null, если учтённых приёмов нет.
    share: number | null;
    taken: number;
    // Принятые, не принятые и пропущенные.
    counted: number;
    on_time: number;
    late: number;
    // «Не принял» по причинам, только встретившиеся.
    skipped: {
        label: string;
        count: number;
        // «Плохо себя чувствовал или побочные эффекты».
        unwell: boolean;
    }[];
    missed: number;
    // Отмечены после закрытия окна приёма.
    marked_late: number;
    // Дни курса без плановых приёмов: пациент не выбрал время.
    no_data_days: number;
    // Доля по времени суток, если приёмы в разное время суток.
    by_time_of_day: { label: string; share: number | null }[] | null;
};

export type DoseDayResult =
    | 'on_time'
    | 'late'
    | 'partial'
    | 'none'
    | 'pending'
    | 'no_data';

export type AdherenceDose = {
    id: number;
    time_of_day: string;
    // Плановое время по поясу пациента: «8:00».
    time: string;
    status: 'scheduled' | 'taken' | 'skipped' | 'missed';
    // «Принят поздно, в 9:30», «Не принят: забыл», «Пропущен», «Запланирован».
    status_text: string;
    unwell: boolean;
    skip_comment: string | null;
    // Отмечен после закрытия окна.
    marked_late: boolean;
    // Когда отмечен: «17.10 в 9:05»; null, если время уже есть в статусе.
    marked_at: string | null;
};

export type RegularDay = DayBase & {
    result: DoseDayResult;
    result_label: string;
    doses: AdherenceDose[];
};

export type AsNeededPeriod = PeriodBase & {
    days_with_doses: number;
    doses: number;
    days_over_limit: number;
};

export type AsNeededDay = DayBase & {
    count: number;
    // Больше, чем разрешала версия назначения в этот день.
    over_limit: boolean;
    // Время приёмов по поясу пациента.
    times: string[];
};

export type RegularCourse = CourseBase & {
    kind: 'regular';
    // Сначала текущий период.
    periods: RegularPeriod[];
    // Последние 28 дней курса по порядку.
    days: RegularDay[];
};

export type AsNeededCourse = CourseBase & {
    kind: 'as_needed';
    periods: AsNeededPeriod[];
    days: AsNeededDay[];
};

export type AdherenceCourse = RegularCourse | AsNeededCourse;

/**
 * Сигнал о приверженности по регулярным назначениям врача, как его отдаёт
 * `App\Support\Adherence\AdherenceSignal`. Пороги — на сервере.
 */
export type AdherenceSignal = {
    // Доля принятых за последние 7 дней или null, если учтённых приёмов нет.
    share: number | null;
    // Доля ниже порога.
    low_share: boolean;
    // Непринятые подряд в конце курса, только если их не меньше порога.
    consecutive_misses: number | null;
    // Сработало хотя бы одно условие.
    raised: boolean;
};

/**
 * Итог дня в календаре пациента: «вовремя» и «поздно» — одно «всё принято».
 */
export type CalendarDayResult =
    | 'taken'
    | 'partial'
    | 'none'
    | 'pending'
    | 'no_data';

export type CalendarDose = {
    id: number;
    // Плановое время по поясу пациента: «8:00».
    time: string;
    drug: string;
    dose: string;
    status: 'scheduled' | 'taken' | 'skipped' | 'missed';
    // «Принят в 8:05», «Не принят: забыл», «Нет отметки», «Ещё не отмечен».
    status_text: string;
    skip_comment: string | null;
};

export type CalendarAsNeeded = {
    drug: string;
    count: number;
    times: string[];
    // Сколько можно в сутки, если приёмов было больше; иначе null.
    over_limit: number | null;
};

export type CalendarDay = {
    date: string;
    day: number;
    // «Пятница, 23 октября».
    label: string;
    today: boolean;
    yesterday: boolean;
    future: boolean;
    // У будущего дня итога нет.
    result: CalendarDayResult | null;
    doses: CalendarDose[];
    as_needed: CalendarAsNeeded[];
    // Не принял из-за самочувствия или побочных эффектов.
    unwell: boolean;
    // Сегодня или вчера с неотмеченными приёмами: отметить можно на «Сегодня».
    can_mark: boolean;
};

/**
 * Месяц календаря приёма, как его отдаёт `App\Support\Adherence\IntakeCalendar`.
 */
export type IntakeCalendarMonth = {
    // «2026-10».
    month: string;
    // «Октябрь 2026».
    title: string;
    // «октябре», в другом году — «октябре 2025».
    in_month: string;
    previous: string | null;
    // Подпись стрелки: «Сентябрь», в другом году — «Декабрь 2025».
    previous_title: string | null;
    next: string | null;
    next_title: string | null;
    // Пустых клеток перед первым числом: неделя с понедельника.
    offset: number;
    taken: number;
    counted: number;
    days: CalendarDay[];
};
