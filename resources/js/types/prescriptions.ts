/**
 * Назначение в том виде, в каком его отдаёт `App\Support\PrescriptionPresenter`.
 */
export type Prescription = {
    id: number;
    drug: {
        code: string;
        name: string;
    };
    strength: string;
    kind: 'regular' | 'as_needed';
    kind_label: string;
    intakes: {
        time_of_day: string;
        dose: string;
    }[];
    as_needed: {
        dose: string;
        limit: string;
    } | null;
    instruction: string | null;
    starts_on: string;
    ends_on: string | null;
    status: 'upcoming' | 'active' | 'completed' | 'changed' | 'cancelled';
    status_label: string;
    prescriber: string | null;
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
