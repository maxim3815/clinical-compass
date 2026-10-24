<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Файл правил подсказки направления не прошёл проверку. Правила в БД при этом не меняются.
 */
class InvalidReferralRules extends RuntimeException
{
    /**
     * @param  list<string>  $errors  Каждая ошибка называет правило и поле.
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(PHP_EOL, $errors));
    }
}
