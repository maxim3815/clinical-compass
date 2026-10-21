<?php

namespace App\Support;

use App\Models\User;
use App\Support\Adherence\AdherenceSignals;
use App\Support\Safety\SafetySignals;

/**
 * Счётчик у пункта меню «Пациенты»: пациенты, которые требуют внимания врача. Это сигнал
 * о приверженности с новыми пропусками или красная находка проверки безопасности без решения.
 */
final class PatientSignals
{
    public static function count(User $doctor): int
    {
        return count(array_unique([
            ...AdherenceSignals::unseenPatientIds($doctor),
            ...SafetySignals::patientIds($doctor),
        ]));
    }
}
