<?php

namespace App\Support\Referrals;

/**
 * Что изменила загрузка правил подсказки направления: коды добавленных, изменённых и выключенных правил.
 */
final readonly class ReferralRuleImportResult
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
