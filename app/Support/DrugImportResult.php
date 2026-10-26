<?php

namespace App\Support;

/**
 * Что изменила загрузка справочника препаратов: коды добавленных, изменённых и выключенных
 * препаратов (справочника и списка препаратов от других врачей) и изменились ли группы препаратов.
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
        public bool $groupsChanged = false,
        // Препаратов в файле списка препаратов от других врачей; null, если список не загружался.
        public ?int $knownTotal = null,
    ) {}

    public function hasChanges(): bool
    {
        return $this->created !== [] || $this->updated !== [] || $this->deactivated !== [] || $this->groupsChanged;
    }
}
