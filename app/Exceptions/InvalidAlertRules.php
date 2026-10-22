<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Файлы правил алертинга не прошли проверку. Правила в БД при этом не меняются.
 */
class InvalidAlertRules extends RuntimeException
{
    /**
     * @param  list<string>  $errors  Каждая ошибка называет файл и поле.
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(PHP_EOL, $errors));
    }
}
