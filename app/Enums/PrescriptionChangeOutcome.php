<?php

namespace App\Enums;

/**
 * Чем закончилось изменение назначения врачом.
 */
enum PrescriptionChangeOutcome
{
    /** Значения те же, ничего не записано. */
    case Unchanged;

    /** Назначение ещё не началось и исправлено на месте. */
    case Edited;

    /** Действующая версия закрыта, создана новая. */
    case NewVersion;
}
