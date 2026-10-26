<?php

namespace App\Http\Controllers\Doctor;

use App\Enums\DrugKind;
use App\Http\Controllers\Controller;
use App\Models\Drug;
use App\Models\DrugStrength;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Inertia\Inertia;
use Inertia\Response;

class DrugController extends Controller
{
    public function index(): Response
    {
        $drugs = Drug::query()
            ->catalog()
            ->active()
            ->with(['strengths' => fn (HasMany $query) => $query->where('is_active', true)->orderBy('mg')])
            ->orderBy('name')
            ->orderBy('code')
            ->get()
            ->map(fn (Drug $drug): array => [
                'code' => $drug->code,
                'name' => $drug->name,
                'inn' => $drug->inn,
                'dosage_form' => $drug->dosage_form,
                'strengths' => $drug->strengths->map(fn (DrugStrength $strength): string => $strength->label())->all(),
            ]);

        return Inertia::render('doctor/drugs/Index', [
            'drugs' => $drugs,
        ]);
    }

    /**
     * Карточка открывается и у выключенного препарата: на него могут ссылаться действующие назначения.
     * У препарата списка препаратов от других врачей карточки справочника нет.
     */
    public function show(Drug $drug): Response
    {
        abort_unless($drug->kind === DrugKind::Catalog, 404);

        $strengths = $drug->strengths()->active()->orderBy('mg')->get();

        return Inertia::render('doctor/drugs/Show', [
            'drug' => [
                'code' => $drug->code,
                'name' => $drug->name,
                'inn' => $drug->inn,
                'atc' => $drug->atc,
                'dosage_form' => $drug->dosage_form,
                'strengths' => $strengths->map(fn (DrugStrength $strength): array => [
                    'label' => $strength->label(),
                    'divisible' => $strength->divisible,
                ])->all(),
                'regimen' => $drug->regimen,
                'patient_instruction' => $drug->patient_instruction,
                'missed_dose' => $drug->missed_dose,
                'window_hours' => $drug->window_hours,
                'is_active' => $drug->is_active,
                'source' => [
                    'trade_name' => $drug->source_trade_name,
                    'document' => $drug->source_document,
                    'registration_number' => $drug->source_registration_number,
                    'url' => $drug->source_url,
                    'edition' => $drug->source_edition,
                ],
            ],
        ]);
    }
}
