/**
 * Назначение в том виде, в каком его отдаёт `App\Support\PrescriptionPresenter`.
 * Назначение врача (`source: 'doctor'`) или препарат, который пациент добавил сам
 * (`source: 'patient'`): у него нет дозировки из справочника, а доза записана текстом.
 */
export type Prescription = {
    id: number;
    source: 'doctor' | 'patient';
    drug: {
        // У «другого» препарата пациента кода справочника нет.
        code: string | null;
        name: string;
    };
    strength: string | null;
    // Доза текстом у препарата пациента, одна на все приёмы.
    dose: string | null;
    kind: 'regular' | 'as_needed';
    kind_label: string;
    intakes: {
        time_of_day: string;
        // Доза приёма у назначения врача, у препарата пациента null.
        dose: string | null;
        // Время, выбранное пациентом («8:00»), или null.
        time: string | null;
    }[];
    as_needed: {
        dose: string;
        limit: string | null;
    } | null;
    instruction: string | null;
    starts_on: string;
    ends_on: string | null;
    status: 'upcoming' | 'active' | 'completed' | 'changed' | 'cancelled';
    status_label: string;
    prescriber: string | null;
    // «Кто назначил» препарат пациента, со слов пациента.
    prescribed_by: string | null;
    closed_at: string | null;
    cancel_reason: string | null;
    cancel_comment: string | null;
    can_change: boolean;
};

/**
 * Вариант выпадающего списка из PHP-перечисления (`options()`).
 */
export type SelectOption = {
    value: string;
    label: string;
};
