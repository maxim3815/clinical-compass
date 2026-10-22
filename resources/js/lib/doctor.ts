import type { InvitingDoctor } from '@/types';

/**
 * Врач одной строкой для гостевых страниц: «Смирнова Анна Сергеевна, психиатр».
 * Специальность внутри фразы — со строчной.
 */
export function doctorLine(doctor: InvitingDoctor): string {
    const specialty = doctor.specialty
        ? doctor.specialty.charAt(0).toLocaleLowerCase('ru') +
          doctor.specialty.slice(1)
        : null;

    return [doctor.name, specialty].filter(Boolean).join(', ');
}
