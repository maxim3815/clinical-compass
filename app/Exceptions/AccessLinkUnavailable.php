<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ссылки нет, она истекла, использована или отозвана. Причину наружу не сообщаем.
 */
class AccessLinkUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ссылка недействительна.');
    }
}
