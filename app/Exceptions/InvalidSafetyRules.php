<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Файлы правил или видов фактов не прошли проверку. Правила в БД при этом не меняются.
 */
class InvalidSafetyRules extends RuntimeException
{
    /**
     * @param  list<string>  $errors  Каждая ошибка называет файл, правило и поле.
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(PHP_EOL, $errors));
    }
}
