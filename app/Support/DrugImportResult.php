<?php

namespace App\Support;

/**
 * Что изменила загрузка справочника препаратов: коды добавленных, изменённых и выключенных препаратов.
 */
final readonly class DrugImportResult
{
    /**
     * @param  list<string>  $created
     * @param  list<string>  $updated
     * @param  list<string>  $deactivated
     */
    public function __construct(
        public int $total,
        public array $created,
        public array $updated,
        public array $deactivated,
    ) {}

    public function hasChanges(): bool
    {
        return $this->created !== [] || $this->updated !== [] || $this->deactivated !== [];
    }
}
