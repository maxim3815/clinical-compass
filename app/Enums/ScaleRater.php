<?php

namespace App\Enums;

/**
 * Кто заполняет шкалу: пациент на своём телефоне или врач на приёме.
 */
enum ScaleRater: string
{
    case Patient = 'patient';
    case Doctor = 'doctor';
}
