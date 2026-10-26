<?php

namespace Database\Factories;

use App\Enums\DrugKind;
use App\Models\Drug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Drug>
 */
class DrugFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Препарат '.fake()->unique()->numerify('####');

        return [
            'code' => 'drug_'.fake()->unique()->numerify('######'),
            'name' => $name,
            'inn' => mb_strtolower($name),
            'atc' => 'N06AB'.fake()->numerify('##'),
            'dosage_form' => 'таблетки, покрытые плёночной оболочкой',
            'regimen' => '1 раз в сутки.',
            'patient_instruction' => 'Независимо от еды.',
            'missed_dose' => 'Не принимайте двойную дозу. Следующую примите в обычное время.',
            'window_hours' => null,
            'source_trade_name' => $name,
            'source_document' => 'Листок-вкладыш из ГРЛС',
            'source_registration_number' => 'ЛП-№('.fake()->numerify('######').')-(РГ-RU)',
            'source_url' => 'https://grls.minzdrav.gov.ru/InstrImg/example.pdf',
            'source_edition' => 'выложен 01.10.2026',
            'is_active' => true,
        ];
    }

    /**
     * Препарат из списка препаратов от других врачей: без инструкции, дозировок и окна приёма.
     *
     * @param  list<string>  $groups
     */
    public function known(array $groups = []): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => DrugKind::Known,
            'groups' => $groups,
            'trade_names' => [$attributes['name'].' форте'],
            'reason' => 'Назван в инструкции препарата справочника.',
            'dosage_form' => null,
            'regimen' => null,
            'patient_instruction' => null,
            'missed_dose' => null,
            'window_hours' => null,
            'source_document' => null,
            'source_url' => null,
            'source_edition' => null,
        ]);
    }

    /**
     * Препарат убран из файла справочника.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
