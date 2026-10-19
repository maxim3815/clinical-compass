<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Файл справочника препаратов не прошёл проверку. Справочник в БД при этом не меняется.
 */
class InvalidDrugCatalog extends RuntimeException
{
    /**
     * @param  list<string>  $errors  Каждая ошибка называет препарат и поле.
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(PHP_EOL, $errors));
    }
}
