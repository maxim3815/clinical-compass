<?php

use App\Models\Drug;
use App\Models\DrugStrength;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('doctor sees active drugs sorted by name with active strengths', function () {
    $sertraline = Drug::factory()->create(['code' => 'sertraline', 'name' => 'Сертралин', 'inn' => 'сертралин']);
    DrugStrength::factory()->for($sertraline)->create(['mg' => 100, 'divisible' => false]);
    DrugStrength::factory()->for($sertraline)->create(['mg' => 50]);
    DrugStrength::factory()->for($sertraline)->inactive()->create(['mg' => 25]);
    $aviandr = Drug::factory()->create(['code' => 'maritupirdine', 'name' => 'Авиандр']);
    DrugStrength::factory()->for($aviandr)->create(['mg' => 20, 'divisible' => false]);
    Drug::factory()->inactive()->create(['name' => 'Агомелатин']);
    Drug::factory()->known(['serotonergic'])->create(['name' => 'Трамадол']);

    $this->actingAs(User::factory()->doctor()->create())
        ->get(route('doctor.drugs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/drugs/Index')
            ->has('drugs', 2)
            ->where('drugs.0.code', 'maritupirdine')
            ->where('drugs.1', [
                'code' => 'sertraline',
                'name' => 'Сертралин',
                'inn' => 'сертралин',
                'dosage_form' => 'таблетки, покрытые плёночной оболочкой',
                'strengths' => ['50 мг', '100 мг'],
            ]),
        );
});

test('drug card shows all catalog fields and the source', function () {
    $drug = Drug::factory()->create([
        'code' => 'sertraline',
        'name' => 'Сертралин',
        'window_hours' => 6,
        'source_trade_name' => 'Золофт',
        'source_registration_number' => 'ЛП-№(002851)-(РГ-RU)',
    ]);
    DrugStrength::factory()->for($drug)->create(['mg' => 100, 'divisible' => false]);
    DrugStrength::factory()->for($drug)->create(['mg' => 50, 'divisible' => true]);
    DrugStrength::factory()->for($drug)->inactive()->create(['mg' => 25]);

    $this->actingAs(User::factory()->doctor()->create())
        ->get(route('doctor.drugs.show', $drug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/drugs/Show')
            ->where('drug', [
                'code' => 'sertraline',
                'name' => 'Сертралин',
                'inn' => $drug->inn,
                'atc' => $drug->atc,
                'dosage_form' => $drug->dosage_form,
                'strengths' => [
                    ['label' => '50 мг', 'divisible' => true],
                    ['label' => '100 мг', 'divisible' => false],
                ],
                'regimen' => $drug->regimen,
                'patient_instruction' => $drug->patient_instruction,
                'missed_dose' => $drug->missed_dose,
                'window_hours' => 6,
                'is_active' => true,
                'source' => [
                    'trade_name' => 'Золофт',
                    'document' => $drug->source_document,
                    'registration_number' => 'ЛП-№(002851)-(РГ-RU)',
                    'url' => $drug->source_url,
                    'edition' => $drug->source_edition,
                ],
            ]),
        );
});

test('card of a drug removed from the catalog still opens', function () {
    $drug = Drug::factory()->inactive()->create();

    $this->actingAs(User::factory()->doctor()->create())
        ->get(route('doctor.drugs.show', $drug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('drug.is_active', false));
});

test('drug from the list of drugs from other doctors has no catalog card', function () {
    $drug = Drug::factory()->known()->create();

    $this->actingAs(User::factory()->doctor()->create())
        ->get(route('doctor.drugs.show', $drug))
        ->assertNotFound();
});

test('unknown drug code answers not found', function (string $code) {
    Drug::factory()->create(['code' => 'sertraline']);

    $this->actingAs(User::factory()->doctor()->create())
        ->get('/doctor/drugs/'.$code)
        ->assertNotFound();
})->with(['unknown', 'Sertraline', '1', 'сертралин']);

test('patient can not open drug pages', function () {
    $drug = Drug::factory()->create();
    $patient = User::factory()->patient()->create();

    $this->actingAs($patient)->get(route('doctor.drugs.index'))->assertForbidden();
    $this->actingAs($patient)->get(route('doctor.drugs.show', $drug))->assertForbidden();
});

test('guest is sent to the login page from drug pages', function () {
    $drug = Drug::factory()->create();

    $this->get(route('doctor.drugs.index'))->assertRedirect(route('login'));
    $this->get(route('doctor.drugs.show', $drug))->assertRedirect(route('login'));
});
