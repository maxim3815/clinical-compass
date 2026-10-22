<?php

namespace App\Support\Assessments;

/**
 * Что изменила загрузка шкал: коды добавленных, изменённых и выключенных шкал.
 */
final readonly class ScaleImportResult
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
