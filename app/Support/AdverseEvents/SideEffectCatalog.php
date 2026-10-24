<?php

namespace App\Support\AdverseEvents;

use App\Enums\SideEffectLevel;
use App\Models\DrugSideEffect;
use App\Models\SideEffect;
use App\Models\User;

/**
 * Список побочных эффектов для пациента: симптомы из листков всех препаратов справочника, которые
 * он сейчас принимает, назначенных врачом и своих. Препарат пациент не выбирает: симптом берёт
 * самую срочную группу среди этих препаратов, а у «нужна помощь сейчас» собираются слова листков
 * о приёме препарата.
 *
 * @phpstan-type Instruction array{drug: string, text: string, url: string}
 * @phpstan-type Entry array{id: int, name: string, term: string, level: SideEffectLevel, position: int, instructions: list<Instruction>}
 */
final class SideEffectCatalog
{
    /**
     * @return array<int, Entry> id симптома => симптом, по группам и порядку словаря
     */
    public static function forPatient(User $patient): array
    {
        $today = $patient->localToday();
        $drugIds = $patient->prescriptions()
            ->current($today)
            ->where('starts_on', '<=', $today->toDateString())
            ->whereNotNull('drug_id')
            ->distinct()
            ->pluck('drug_id');

        $entries = [];

        $rows = DrugSideEffect::query()
            ->whereIn('drug_id', $drugIds)
            ->whereHas('sideEffect', fn ($query) => $query->where('is_active', true))
            ->with(['sideEffect', 'drug'])
            ->get();

        foreach ($rows as $row) {
            $id = $row->side_effect_id;
            $entry = $entries[$id] ?? [
                'id' => $id,
                'name' => $row->sideEffect->name,
                'term' => $row->sideEffect->term,
                'level' => $row->level,
                'position' => $row->sideEffect->position,
                'instructions' => [],
            ];
            $entry['level'] = SideEffectLevel::strongest($entry['level'], $row->level);

            if ($row->level === SideEffectLevel::Emergency && $row->instruction !== null) {
                $entry['instructions'][] = [
                    'drug' => $row->drug->name,
                    'text' => $row->instruction,
                    'url' => $row->drug->source_url,
                ];
            }

            $entries[$id] = $entry;
        }

        uasort($entries, fn (array $a, array $b): int => [self::groupOrder($a['level']), $a['position']] <=> [self::groupOrder($b['level']), $b['position']]);

        return $entries;
    }

    /**
     * Весь словарь: врач записывает и симптом препарата, которого пациент уже не принимает.
     *
     * @return list<array{id: int, name: string, term: string}>
     */
    public static function dictionary(): array
    {
        return array_values(SideEffect::query()
            ->active()
            ->orderBy('term')
            ->get()
            ->map(fn (SideEffect $symptom): array => ['id' => $symptom->id, 'name' => $symptom->name, 'term' => $symptom->term])
            ->all());
    }

    /**
     * Группы для формы пациента: «нужна помощь сейчас» первой, со звонком в 112 (встревоженный
     * пациент отмечает первое похожее), затем частые, реже и «врач получит срочное уведомление».
     *
     * @param  array<int, Entry>  $entries
     * @return list<array{level: string, label: string, symptoms: list<array{id: int, code: string, name: string}>}>
     */
    public static function groups(array $entries): array
    {
        $codes = SideEffect::query()->whereKey(array_keys($entries))->pluck('code', 'id');
        $groups = [];

        foreach ($entries as $entry) {
            $key = self::groupOrder($entry['level']);
            $groups[$key] ??= [
                'level' => $entry['level']->value,
                'label' => $entry['level']->groupLabel(),
                'symptoms' => [],
            ];
            $groups[$key]['symptoms'][] = ['id' => $entry['id'], 'code' => (string) $codes[$entry['id']], 'name' => $entry['name']];
        }

        ksort($groups);

        return array_values($groups);
    }

    private static function groupOrder(SideEffectLevel $level): int
    {
        return match ($level) {
            SideEffectLevel::Emergency => 0,
            SideEffectLevel::Common => 1,
            SideEffectLevel::Uncommon => 2,
            SideEffectLevel::Urgent => 3,
        };
    }
}
