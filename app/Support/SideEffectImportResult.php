<?php

namespace App\Support;

/**
 * Что изменила загрузка побочных эффектов: сколько симптомов в файле, сколько препаратов с группами
 * и изменилось ли что-нибудь.
 */
final readonly class SideEffectImportResult
{
    public function __construct(
        public int $symptoms,
        public int $drugs,
        public bool $changed,
    ) {}
}
