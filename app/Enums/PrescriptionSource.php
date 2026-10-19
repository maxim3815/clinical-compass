<?php

namespace App\Enums;

/**
 * Кто ведёт назначение: лечащий врач или сам пациент (препарат от другого врача).
 */
enum PrescriptionSource: string
{
    case Doctor = 'doctor';
    case Patient = 'patient';
}
