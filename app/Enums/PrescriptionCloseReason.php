<?php

namespace App\Enums;

/**
 * Почему версия назначения закрыта: врач изменил назначение (появилась новая версия) или отменил его.
 */
enum PrescriptionCloseReason: string
{
    case Changed = 'changed';
    case Cancelled = 'cancelled';
}
