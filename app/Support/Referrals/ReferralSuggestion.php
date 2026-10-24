<?php

namespace App\Support\Referrals;

use App\Enums\ReferralCategory;

/**
 * Подсказка направления к психиатру: категория по самому сильному сработавшему правилу, все
 * сработавшие правила с тем, на что они смотрели, и шкалы, без которых часть правил не проверена.
 *
 * @phpstan-type ReferralEvidence array{type: 'answer', scale: string, name: string, item: int, text: string, score: int, label: string}|array{type: 'total', scale: string, name: string, total: int, max: int, band: string}|array{type: 'fact', code: string, name: string, value: string}
 * @phpstan-type ReferralMatchData array{code: string, category: string, text: string, evidence: list<ReferralEvidence>, source: array{document: string, section: string, url: string, quote?: string|null}}
 * @phpstan-type MissingScale array{scale: string, name: string, rater: string}
 */
final readonly class ReferralSuggestion
{
    /**
     * @param  list<ReferralMatchData>  $matches  сначала «обязательно», внутри — по порядку правил в файле
     * @param  list<MissingScale>  $missing
     */
    public function __construct(
        public ?ReferralCategory $category,
        public array $matches,
        public array $missing,
    ) {}

    /**
     * @return array{category: string|null, matches: list<ReferralMatchData>, missing: list<MissingScale>}
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category?->value,
            'matches' => $this->matches,
            'missing' => $this->missing,
        ];
    }
}
