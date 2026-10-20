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
