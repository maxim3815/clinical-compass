<?php

namespace App\Support;

use App\Models\User;
use App\Support\Adherence\AdherenceSignals;
use App\Support\AdverseEvents\AdverseEventPresenter;
use App\Support\Alerts\AlertSignals;
use App\Support\Referrals\ReferralPresenter;
use App\Support\Safety\SafetySignals;

/**
 * Счётчик у пункта меню «Пациенты»: пациенты, которые требуют внимания врача. Это открытый
 * алерт, сигнал о приверженности с новыми пропусками, красная находка проверки безопасности
 * без решения, непросмотренный побочный эффект или направление терапевта, которое ждёт психиатра.
 */
final class PatientSignals
{
    public static function count(User $doctor): int
    {
        return count(array_unique([
            ...AlertSignals::patientIds($doctor),
            ...AdherenceSignals::unseenPatientIds($doctor),
            ...SafetySignals::patientIds($doctor),
            ...AdverseEventPresenter::patientIds($doctor),
            ...ReferralPresenter::incomingPatientIds($doctor),
        ]));
    }
}
