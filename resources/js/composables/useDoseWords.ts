import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';

export type DoseWords = {
    took: string;
    didNotTake: string;
    tookAll: string;
    tookAtAnotherTime: string;
    notAll: string;
};

/**
 * Подписи кнопок отметки приёма от лица пациента. Пациентке — в женском роде: «Приняла»,
 * «Не приняла». Если пол в профиле не указан, подписи в мужском роде.
 */
export function useDoseWords(): ComputedRef<DoseWords> {
    const page = usePage();

    return computed(() => {
        const took = page.props.femalePatient ? 'Приняла' : 'Принял';

        return {
            took,
            didNotTake: `Не ${took.toLowerCase()}`,
            tookAll: `${took} всё`,
            tookAtAnotherTime: `${took} в другое время`,
            notAll: `Не ${took.toLowerCase()} или ${took.toLowerCase()} не всё`,
        };
    });
}
