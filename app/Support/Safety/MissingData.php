<?php

namespace App\Support\Safety;

use RuntimeException;

/**
 * Условию правила не хватило данных пациента: правило не срабатывает, а данные попадают в «Не проверено».
 */
final class MissingData extends RuntimeException
{
    /**
     * @param  string  $label  чего не хватило, для врача: «СКФ», «пол», «диагноз»
     */
    public function __construct(public readonly string $label)
    {
        parent::__construct("Нет данных: {$label}.");
    }
}
