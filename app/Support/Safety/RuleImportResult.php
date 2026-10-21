<?php

namespace App\Support\Safety;

/**
 * Что изменила загрузка правил: коды добавленных, изменённых и выключенных правил и видов фактов.
 */
final readonly class RuleImportResult
{
    /**
     * @param  list<string>  $created
     * @param  list<string>  $updated
     * @param  list<string>  $deactivated
     * @param  list<string>  $typesChanged  коды добавленных, изменённых и выключенных видов фактов
     */
    public function __construct(
        public int $total,
        public array $created,
        public array $updated,
        public array $deactivated,
        public array $typesChanged,
    ) {}

    public function hasChanges(): bool
    {
        return $this->created !== [] || $this->updated !== [] || $this->deactivated !== [] || $this->typesChanged !== [];
    }
}
