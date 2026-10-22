<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Файлы шкал не прошли проверку. Шкалы в БД при этом не меняются.
 */
class InvalidScales extends RuntimeException
{
    /**
     * @param  list<string>  $errors  Каждая ошибка называет файл и поле.
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(PHP_EOL, $errors));
    }
}
