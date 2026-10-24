<?php

namespace App\Support\AdverseEvents;

use App\Enums\SideEffectLevel;
use App\Models\AdverseEvent;
use App\Models\Alert;

/**
 * Что вышло из отчёта пациента: записи, алерт врачу по срочному симптому и слова листков о приёме
 * препарата для симптомов «нужна помощь сейчас».
 *
 * @phpstan-import-type Instruction from SideEffectCatalog
 */
final readonly class AdverseEventReport
{
    /**
     * @param  list<AdverseEvent>  $events
     * @param  list<Instruction>  $instructions
     */
    public function __construct(
        public array $events,
        public ?SideEffectLevel $level,
        public ?Alert $alert,
        public array $instructions,
    ) {}

    /**
     * Что показать пациенту после отправки: обычный симптом, «сообщить врачу сразу»
     * или «нужна помощь сейчас». «Срочно» — только если врачу ушёл алерт, а 112 нужен
     * при «нужна помощь сейчас» в любом случае.
     *
     * @return 'regular'|'urgent'|'emergency'
     */
    public function kind(): string
    {
        return match (true) {
            $this->level === SideEffectLevel::Emergency => 'emergency',
            $this->level === SideEffectLevel::Urgent && $this->alert !== null => 'urgent',
            default => 'regular',
        };
    }
}
