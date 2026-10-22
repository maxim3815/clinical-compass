<?php

namespace App\Support\Alerts;

/**
 * Что изменила загрузка правил алертинга: коды добавленных, изменённых и выключенных правил.
 */
final readonly class AlertRuleImportResult
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
