<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Файл побочных эффектов не прошёл проверку. Список в БД при этом не меняется.
 */
class InvalidSideEffects extends RuntimeException
{
    /**
     * @param  list<string>  $errors  Каждая ошибка называет симптом или препарат и поле.
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(PHP_EOL, $errors));
    }
}
