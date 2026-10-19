<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Назначение уже изменено, отменено или завершено, либо его ведёт пациент: врач не может его менять,
 * а пациент — выбирать в нём время приёма.
 */
class PrescriptionNotChangeable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Назначение уже изменено, отменено или завершено.');
    }
}
